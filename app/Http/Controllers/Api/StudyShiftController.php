<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\StudyShift;
use App\Models\User;
use App\Support\ApiListCache;
use App\Support\PlatformInstitutionHelper;
use App\Support\PlatformTenantScope;
use App\Services\StudyShiftProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StudyShiftController extends Controller
{
    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function index(Request $request, StudyShiftProvisioningService $provisioning)
    {
        $courseId = $request->query('course_id');
        $activeOnly = $request->boolean('active_only', true);
        $manage = $request->boolean('manage');
        $ensureDefaults = $request->boolean('ensure_defaults');

        if (!$manage && $courseId && $activeOnly && !$request->boolean('group_by_day')) {
            $course = Course::find((int) $courseId);
            if (!$course) {
                return response()->json(['study_shifts' => []], 200);
            }

            $institutionId = $request->query('platform_institution_id');
            $institutionId = $institutionId !== null && $institutionId !== ''
                ? (int) $institutionId
                : ($course->platform_institution_id ? (int) $course->platform_institution_id : null);

            $cacheKey = 'course_' . (int) $courseId . '_active'
                . ($institutionId ? '_inst_' . $institutionId : '')
                . ($ensureDefaults ? '_ensure' : '');

            $rows = ApiListCache::remember('study_shifts', $cacheKey, 120, function () use ($course, $institutionId, $ensureDefaults, $provisioning) {
                $shifts = $provisioning->shiftsForCourseRegistration($course, $institutionId);

                if ($shifts->isEmpty() && $ensureDefaults) {
                    $shifts = $provisioning->ensureDefaultsForCourse($course);
                }

                return $shifts
                    ->map(fn (StudyShift $shift) => $this->serializeShift($shift, null))
                    ->values()
                    ->all();
            });

            return response()->json(['study_shifts' => $rows], 200);
        }

        $query = StudyShift::query()
            ->withCount('enrollmentLinks')
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($manage) {
            $query->with(['course:id,title', 'courses:id,title', 'creator:id,name,email,role']);
        } else {
            $query->with(['course:id,title', 'courses:id,title']);
        }

        if ($manage) {
            $actor = $this->resolveActor($request);
            if (!$actor) {
                return response()->json(['message' => 'Email is required to manage study shifts.'], 401);
            }

            if ($this->isPlatformAdmin($actor)) {
                // Platform admin sees every shift.
            } elseif ($this->isPartnerAdmin($actor)) {
                $tenantId = (int) $actor->platform_institution_id;
                $courseIds = PlatformTenantScope::tenantCourseIds($tenantId);
                $query->where(function ($q) use ($tenantId, $courseIds) {
                    $q->where('platform_institution_id', $tenantId);
                    if (!empty($courseIds)) {
                        $q->orWhereIn('course_id', $courseIds)
                            ->orWhereHas('courses', fn ($sub) => $sub->whereIn('courses.id', $courseIds));
                    }
                });
            } elseif ($this->isInstructor($actor)) {
                $courseIds = $this->instructorCourseIds($actor);
                $ids = $courseIds->isEmpty() ? [-1] : $courseIds->all();
                $query->where(function ($q) use ($ids) {
                    $q->whereIn('course_id', $ids)
                        ->orWhereHas('courses', fn ($sub) => $sub->whereIn('courses.id', $ids));
                });
            } else {
                return response()->json(['message' => 'You are not allowed to manage study shifts.'], 403);
            }
        }

        if ($courseId) {
            $cid = (int) $courseId;
            $query->where(function ($q) use ($cid) {
                $q->where('course_id', $cid)
                    ->orWhereNull('course_id')
                    ->orWhereHas('courses', fn ($sub) => $sub->where('courses.id', $cid));
            });
        }

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        $actor = $manage ? $this->resolveActor($request) : null;
        $rows = $query->get()->map(fn (StudyShift $shift) => $this->serializeShift($shift, $actor));

        if ($request->boolean('group_by_day')) {
            $grouped = collect(self::DAY_NAMES)->map(function (string $label, int $day) use ($rows) {
                $dayShifts = $rows->filter(fn (array $row) => (int) $row['day_of_week'] === $day)->values();

                return [
                    'day_of_week' => $day,
                    'day_label' => $label,
                    'shifts' => $dayShifts,
                ];
            })->values();

            return response()->json([
                'study_shifts' => $rows,
                'by_day' => $grouped,
            ], 200);
        }

        return response()->json(['study_shifts' => $rows], 200);
    }

    public function store(Request $request, StudyShiftProvisioningService $provisioning)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->isPlatformAdmin($actor) && !$this->isPartnerAdmin($actor) && !$this->isInstructor($actor)) {
            return response()->json(['message' => 'You are not allowed to create study shifts.'], 403);
        }

        $data = $this->validatedPayload($request, false, $actor);
        $courseIds = $this->extractCourseIds($data);
        $days = $data['days_of_week'] ?? null;
        $timeSlots = $this->normalizeTimeSlots($data);
        unset($data['days_of_week'], $data['course_ids'], $data['time_slots']);

        if ($this->isInstructor($actor)) {
            if ($courseIds === []) {
                return response()->json([
                    'message' => 'Instructors must assign a study shift to at least one of their courses.',
                ], 422);
            }
        }

        if (count($courseIds) > 1) {
            return response()->json([
                'message' => 'Assign only one course per availability block.',
            ], 422);
        }

        foreach ($courseIds as $courseId) {
            if (!$this->canManageCourse($actor, $courseId)) {
                return response()->json(['message' => 'You cannot manage study shifts for one of the selected courses.'], 403);
            }
        }

        $dayList = is_array($days) && count($days) > 0
            ? array_values(array_unique(array_map('intval', $days)))
            : (isset($data['day_of_week']) ? [(int) $data['day_of_week']] : []);

        if (count($dayList) === 0) {
            return response()->json(['message' => 'Select at least one day of the week.'], 422);
        }

        unset($data['day_of_week']);
        $data['course_id'] = $courseIds[0] ?? $data['course_id'] ?? null;

        $created = [];
        foreach ($dayList as $day) {
            foreach ($timeSlots as $slot) {
                $this->assertInstructorSlotCourseExclusive(
                    $actor,
                    $day,
                    $slot['start_time'],
                    $slot['end_time'],
                    $courseIds
                );

                $shift = StudyShift::create([
                    ...$data,
                    'name' => $slot['name'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'day_of_week' => $day,
                    'created_by' => $actor->id,
                    'platform_institution_id' => $this->resolveShiftInstitutionId($data['course_id'] ?? null, $actor),
                ]);
                $provisioning->syncShiftCourses($shift, $courseIds);
                $shift->load(['course:id,title', 'courses:id,title', 'creator:id,name,email,role']);
                $shift->loadCount('enrollmentLinks');
                $created[] = $this->serializeShift($shift, $actor);
            }
        }

        $count = count($created);

        $this->bumpStudyShiftCaches();

        return response()->json([
            'message' => $count === 1 ? 'Study shift created.' : "{$count} study shifts created.",
            'study_shift' => $created[0] ?? null,
            'study_shifts' => $created,
        ], 201);
    }

    public function update(Request $request, StudyShift $studyShift, StudyShiftProvisioningService $provisioning)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageShift($actor, $studyShift)) {
            return response()->json(['message' => 'You cannot manage this study shift.'], 403);
        }

        $data = $this->validatedPayload($request, true, $actor);
        $courseIds = $this->extractCourseIds($data, $studyShift);
        unset($data['course_ids'], $data['time_slots']);

        if (count($courseIds) > 1) {
            return response()->json([
                'message' => 'Assign only one course per availability block.',
            ], 422);
        }

        foreach ($courseIds as $courseId) {
            if (!$this->canManageCourse($actor, $courseId)) {
                return response()->json(['message' => 'You cannot assign this shift to one of the selected courses.'], 403);
            }
        }

        if (array_key_exists('course_id', $data) && $data['course_id'] && !$this->canManageCourse($actor, $data['course_id'])) {
            return response()->json(['message' => 'You cannot assign this shift to that course.'], 403);
        }

        $day = (int) ($data['day_of_week'] ?? $studyShift->day_of_week);
        $start = substr((string) ($data['start_time'] ?? $studyShift->start_time), 0, 5);
        $end = substr((string) ($data['end_time'] ?? $studyShift->end_time), 0, 5);
        $this->assertInstructorSlotCourseExclusive($actor, $day, $start, $end, $courseIds, $studyShift->id);

        $studyShift->update($data);
        if ($request->has('course_ids') || $request->has('course_id')) {
            $provisioning->syncShiftCourses($studyShift, $courseIds);
        }
        $studyShift->load(['course:id,title', 'courses:id,title', 'creator:id,name,email,role']);
        $studyShift->loadCount('enrollmentLinks');

        $this->bumpStudyShiftCaches();

        return response()->json([
            'message' => 'Study shift updated.',
            'study_shift' => $this->serializeShift($studyShift, $actor),
        ], 200);
    }

    public function destroy(Request $request, StudyShift $studyShift)
    {
        $actor = $this->resolveActor($request);
        if (!$actor) {
            return response()->json(['message' => 'Email is required.'], 401);
        }

        if (!$this->canManageShift($actor, $studyShift)) {
            return response()->json(['message' => 'You cannot delete this study shift.'], 403);
        }

        $studyShift->delete();

        $this->bumpStudyShiftCaches();

        return response()->json(['message' => 'Study shift deleted.'], 200);
    }

    private function validatedPayload(Request $request, bool $partial, ?User $actor): array
    {
        $rules = [
            'course_id' => 'nullable|integer|exists:courses,id',
            'course_ids' => 'nullable|array',
            'course_ids.*' => 'integer|exists:courses,id',
            'name' => ($partial ? 'sometimes|' : '') . 'required|string|max:120',
            'day_of_week' => ($partial ? 'sometimes|' : '') . 'nullable|integer|min:0|max:6',
            'days_of_week' => ($partial ? 'sometimes|' : '') . 'nullable|array|min:1',
            'days_of_week.*' => 'integer|min:0|max:6',
            'start_time' => ($partial ? 'sometimes|' : '') . 'required_without:time_slots|string|max:8',
            'end_time' => ($partial ? 'sometimes|' : '') . 'required_without:time_slots|string|max:8',
            'time_slots' => ($partial ? 'sometimes|' : '') . 'nullable|array|min:1',
            'time_slots.*.name' => 'required_with:time_slots|string|max:120',
            'time_slots.*.start_time' => 'required_with:time_slots|string|max:8',
            'time_slots.*.end_time' => 'required_with:time_slots|string|max:8',
            'timezone' => ($partial ? 'sometimes|' : '') . 'required|string|max:64',
            'max_students' => 'nullable|integer|min:1|max:500',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
            'email' => 'nullable|email',
        ];

        $data = $request->validate($rules);
        unset($data['email']);

        if (!$partial && empty($data['day_of_week']) && empty($data['days_of_week'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'days_of_week' => ['Select at least one day of the week.'],
            ]);
        }

        if (!$partial && empty($data['time_slots']) && (empty($data['start_time']) || empty($data['end_time']))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'time_slots' => ['Add at least one time slot.'],
            ]);
        }

        if (isset($data['time_slots']) && is_array($data['time_slots'])) {
            foreach ($data['time_slots'] as $index => $slot) {
                $start = substr((string) ($slot['start_time'] ?? ''), 0, 5);
                $end = substr((string) ($slot['end_time'] ?? ''), 0, 5);
                if ($this->timeToMinutes($end) <= $this->timeToMinutes($start)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "time_slots.{$index}.end_time" => ['End time must be after start time.'],
                    ]);
                }
            }
        }

        if (isset($data['start_time'])) {
            $data['start_time'] = substr($data['start_time'], 0, 5);
        }
        if (isset($data['end_time'])) {
            $data['end_time'] = substr($data['end_time'], 0, 5);
        }

        if (isset($data['start_time'], $data['end_time'])) {
            $start = $this->timeToMinutes($data['start_time']);
            $end = $this->timeToMinutes($data['end_time']);
            if ($end <= $start) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'end_time' => ['End time must be after start time.'],
                ]);
            }
        }

        if (!isset($data['timezone']) && !$partial) {
            $data['timezone'] = 'Africa/Kigali';
        }

        if (isset($data['days_of_week']) && is_array($data['days_of_week'])) {
            $data['days_of_week'] = array_values(array_unique(array_map('intval', $data['days_of_week'])));
        }

        return $data;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', substr($time, 0, 5));

        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
    }

    private function resolveActor(Request $request): ?User
    {
        if ($user = Auth::user()) {
            return $user;
        }

        $email = $request->input('user_email')
            ?? $request->query('user_email')
            ?? $request->query('email')
            ?? $request->input('email')
            ?? $request->header('X-User-Email');

        if (!$email) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function isPlatformAdmin(User $user): bool
    {
        return in_array(strtolower((string) $user->role), ['admin', 'superadmin', 'staff'], true)
            && empty($user->platform_institution_id);
    }

    private function isPartnerAdmin(User $user): bool
    {
        return PlatformInstitutionHelper::isPartnerCompanyAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $this->isPlatformAdmin($user) || $this->isPartnerAdmin($user);
    }

    private function isInstructor(User $user): bool
    {
        return strtolower((string) $user->role) === 'instructor';
    }

    private function instructorCourseIds(User $instructor)
    {
        return $instructor->assignedCourses()->pluck('courses.id');
    }

    private function canManageCourse(User $user, ?int $courseId): bool
    {
        if (!$courseId) {
            return false;
        }

        $course = Course::query()->find($courseId);
        if (!$course || !PlatformTenantScope::userOwnsCourse($user, $course)) {
            return false;
        }

        if ($this->isAdmin($user) || PlatformInstitutionHelper::isPartnerCompanyAdmin($user)) {
            return true;
        }

        if (!$this->isInstructor($user)) {
            return false;
        }

        return Course::query()
            ->where('id', $courseId)
            ->whereHas('instructors', fn ($q) => $q->where('users.id', $user->id))
            ->exists();
    }

    private function canManageShift(User $user, StudyShift $shift): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$this->isInstructor($user)) {
            return false;
        }

        $allowed = $this->instructorCourseIds($user);

        if ($shift->course_id && $allowed->contains((int) $shift->course_id)) {
            return true;
        }

        return $shift->courses()
            ->whereIn('courses.id', $allowed->isEmpty() ? [-1] : $allowed)
            ->exists();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function extractCourseIds(array $data, ?StudyShift $existing = null): array
    {
        if (array_key_exists('course_ids', $data)) {
            return array_values(array_unique(array_filter(array_map('intval', $data['course_ids'] ?? []))));
        }

        if (array_key_exists('course_id', $data)) {
            return $data['course_id'] ? [(int) $data['course_id']] : [];
        }

        if ($existing) {
            $fromPivot = $existing->courses()->pluck('courses.id')->map(fn ($id) => (int) $id)->all();
            if ($fromPivot !== []) {
                return $fromPivot;
            }

            return $existing->course_id ? [(int) $existing->course_id] : [];
        }

        return [];
    }

    private function serializeShift(StudyShift $shift, ?User $actor = null): array
    {
        $enrolled = (int) ($shift->enrollment_links_count ?? $shift->enrollmentLinks()->count());
        $max = $shift->max_students;
        $creator = $shift->relationLoaded('creator') ? $shift->creator : null;

        $linkedCourses = $shift->relationLoaded('courses')
            ? $shift->courses
            : $shift->courses()->get(['courses.id', 'courses.title']);

        $courseIds = $linkedCourses->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($courseIds === [] && $shift->course_id) {
            $courseIds = [(int) $shift->course_id];
        }

        $courseTitles = $linkedCourses->pluck('title')->filter()->values()->all();
        if ($courseTitles === [] && $shift->course?->title) {
            $courseTitles = [$shift->course->title];
        }

        $coursesPayload = $linkedCourses->map(fn (Course $c) => [
            'id' => (int) $c->id,
            'title' => $c->title,
        ])->values()->all();

        return [
            'id' => $shift->id,
            'course_id' => $shift->course_id,
            'course_ids' => $courseIds,
            'courses' => $coursesPayload,
            'course_title' => $courseTitles !== [] ? implode(', ', $courseTitles) : null,
            'course_titles' => $courseTitles,
            'name' => $shift->name,
            'day_of_week' => (int) $shift->day_of_week,
            'day_label' => self::DAY_NAMES[(int) $shift->day_of_week] ?? 'Day',
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'timezone' => $shift->timezone,
            'max_students' => $max,
            'enrolled_count' => $enrolled,
            'seats_available' => $max ? max(0, $max - $enrolled) : null,
            'is_full' => $max ? $enrolled >= $max : false,
            'is_active' => (bool) $shift->is_active,
            'notes' => $shift->notes,
            'created_by' => $shift->created_by,
            'created_by_name' => $creator?->name,
            'created_by_role' => $creator?->role,
            'can_manage' => $actor ? $this->canManageShift($actor, $shift) : false,
            'label' => sprintf(
                '%s · %s %s–%s',
                $shift->name,
                self::DAY_NAMES[(int) $shift->day_of_week] ?? 'Day',
                substr((string) $shift->start_time, 0, 5),
                substr((string) $shift->end_time, 0, 5)
            ),
        ];
    }

    private function bumpStudyShiftCaches(): void
    {
        ApiListCache::bump('study_shifts');
    }

    private function resolveShiftInstitutionId(?int $courseId, User $actor): ?int
    {
        if ($courseId) {
            $course = Course::find($courseId);
            if ($course?->platform_institution_id) {
                return (int) $course->platform_institution_id;
            }
        }

        if (!empty($actor->platform_institution_id)) {
            return (int) $actor->platform_institution_id;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{name: string, start_time: string, end_time: string}>
     */
    private function normalizeTimeSlots(array $data): array
    {
        if (!empty($data['time_slots']) && is_array($data['time_slots'])) {
            return array_values(array_map(function (array $slot) {
                return [
                    'name' => trim((string) ($slot['name'] ?? 'Study shift')),
                    'start_time' => substr((string) ($slot['start_time'] ?? '09:00'), 0, 5),
                    'end_time' => substr((string) ($slot['end_time'] ?? '11:00'), 0, 5),
                ];
            }, $data['time_slots']));
        }

        return [[
            'name' => trim((string) ($data['name'] ?? 'Study shift')),
            'start_time' => substr((string) ($data['start_time'] ?? '09:00'), 0, 5),
            'end_time' => substr((string) ($data['end_time'] ?? '11:00'), 0, 5),
        ]];
    }

    /**
     * @return array<int, int>
     */
    private function shiftLinkedCourseIds(StudyShift $shift): array
    {
        $fromPivot = $shift->relationLoaded('courses')
            ? $shift->courses->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $shift->courses()->pluck('courses.id')->map(fn ($id) => (int) $id)->all();

        if ($fromPivot !== []) {
            return $fromPivot;
        }

        return $shift->course_id ? [(int) $shift->course_id] : [];
    }

    /**
     * Instructors cannot assign two different courses to the same day + time block.
     *
     * @param array<int, int> $courseIds
     */
    private function assertInstructorSlotCourseExclusive(
        User $actor,
        int $day,
        string $start,
        string $end,
        array $courseIds,
        ?int $excludeShiftId = null
    ): void {
        if (!$this->isInstructor($actor)) {
            return;
        }

        $newCourseId = $courseIds[0] ?? null;
        if (!$newCourseId) {
            return;
        }

        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);
        $instructorCourseIds = $this->instructorCourseIds($actor);
        $managedIds = $instructorCourseIds->isEmpty() ? [-1] : $instructorCourseIds->all();

        $existing = StudyShift::query()
            ->where('day_of_week', $day)
            ->whereRaw('substr(start_time, 1, 5) = ?', [$start])
            ->whereRaw('substr(end_time, 1, 5) = ?', [$end])
            ->where(function ($q) use ($actor, $managedIds) {
                $q->where('created_by', $actor->id)
                    ->orWhereIn('course_id', $managedIds)
                    ->orWhereHas('courses', fn ($sub) => $sub->whereIn('courses.id', $managedIds));
            })
            ->when($excludeShiftId, fn ($q) => $q->where('id', '!=', $excludeShiftId))
            ->with('courses:id,title')
            ->get();

        foreach ($existing as $shift) {
            $linked = $this->shiftLinkedCourseIds($shift);
            if ($linked === []) {
                continue;
            }

            if (!in_array($newCourseId, $linked, true)) {
                $dayLabel = self::DAY_NAMES[$day] ?? 'Day';
                $otherCourse = $shift->courses->first()?->title ?? $shift->course?->title ?? 'another course';

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'start_time' => [
                        sprintf(
                            'You already have %s on %s at %s–%s. Each time slot can only be assigned to one of your courses.',
                            $otherCourse,
                            $dayLabel,
                            $start,
                            $end
                        ),
                    ],
                ]);
            }
        }
    }
}
