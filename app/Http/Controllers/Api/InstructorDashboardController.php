<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\InstructorPayoutRequest;
use App\Models\StudyShift;
use App\Support\InstructorPayoutMethods;
use App\Models\User;
use App\Support\InstructorLookup;
use App\Support\CourseMaterialHelper;
use App\Support\QuizMaterialHelper;
use App\Support\CourseDetailsHelper;
use App\Support\CourseRevenueCalculator;
use App\Support\PlatformTenantScope;
use App\Services\LiveClassLobbyService;
use App\Services\ZoomService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InstructorDashboardController extends Controller
{
    public function __construct(
        protected ZoomService $zoom,
        protected LiveClassLobbyService $lobbyService,
    ) {
    }

    private function sharePercent(): float
    {
        return (float) config('app.instructor_share_percent', 70);
    }

    private function findInstructor(string $email): ?User
    {
        return InstructorLookup::byEmail($email);
    }

    private function findLiveClassHost(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
            ->whereIn('role', ['instructor', 'admin', 'staff', 'partner_company'])
            ->first();
    }

    private function partnerOwnsCourse(User $user, int $courseId): bool
    {
        $institutionId = (int) ($user->platform_institution_id ?? 0);
        if ($institutionId <= 0 || $courseId <= 0) {
            return false;
        }

        $courseInstitutionId = Course::query()->whereKey($courseId)->value('platform_institution_id');

        return $courseInstitutionId !== null && (int) $courseInstitutionId === $institutionId;
    }

    private function canHostMaterial(User $user, CourseMaterial $material): bool
    {
        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'staff'], true)) {
            return true;
        }

        if ($role === 'partner_company') {
            return $this->partnerOwnsCourse($user, (int) $material->course_id);
        }

        if ($role !== 'instructor') {
            return false;
        }

        return $this->courseIdsFor($user)->contains($material->course_id);
    }

    private function canHostCourse(User $user, ?Course $course): bool
    {
        if (!$course) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'staff'], true)) {
            return true;
        }

        if ($role === 'partner_company') {
            return $this->partnerOwnsCourse($user, (int) $course->id);
        }

        if ($role !== 'instructor') {
            return false;
        }

        return $this->courseIdsFor($user)->contains($course->id);
    }

    private function courseIdsFor(User $instructor)
    {
        return $instructor->assignedCourses()->pluck('courses.id');
    }

    private function courseRevenue(Course $course): float
    {
        return CourseRevenueCalculator::courseRevenue($course);
    }

    public function dashboard(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);
        $share = $this->sharePercent();

        $courses = $instructor->assignedCourses()
            ->withCount([
                'enrollments as enrollments_count',
                'enrollments as paid_enrollments_count' => fn ($q) => $q->where('status', 'paid'),
                'materials as materials_count',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(function (Course $course) use ($share) {
                $uniqueStudents = CourseEnrollment::query()
                    ->where('course_id', $course->id)
                    ->distinct('student_id')
                    ->count('student_id');

                $revenue = $this->courseRevenue($course);

                return [
                    'id' => $course->id,
                    'title' => $course->title,
                    'description' => $course->description,
                    'status' => $course->status,
                    'price' => (float) ($course->price ?? 0),
                    'duration' => $course->duration,
                    'students_count' => $uniqueStudents,
                    'enrollments_count' => (int) $course->enrollments_count,
                    'paid_enrollments_count' => (int) $course->paid_enrollments_count,
                    'materials_count' => (int) $course->materials_count,
                    'revenue' => $revenue,
                    'earnings' => round($revenue * ($share / 100), 2),
                ];
            })
            ->values();

        $totalRevenue = $courses->sum('revenue');
        $totalEarnings = round($totalRevenue * ($share / 100), 2);

        $paidOut = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['approved', 'paid', 'completed'])
            ->sum('amount');

        $pendingPayouts = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $availableBalance = max(0, round($totalEarnings - $paidOut - $pendingPayouts, 2));

        $now = Carbon::now();
        $months = collect(range(5, 0))->map(fn ($i) => $now->copy()->subMonths($i)->format('Y-m'));

        $enrollmentRows = $courseIds->isEmpty()
            ? collect()
            : CourseEnrollment::query()
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->whereIn('course_id', $courseIds)
                ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
                ->groupBy('month')
                ->pluck('count', 'month');

        $enrollmentsByMonth = $months->map(fn ($month) => [
            'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
            'count' => (int) ($enrollmentRows[$month] ?? 0),
        ])->values();

        $courseIdList = $courseIds->all();
        $since = $now->copy()->subMonths(5)->startOfMonth();

        $paymentRows = $courseIds->isEmpty()
            ? collect()
            : CourseRevenueCalculator::monthlyPaymentRevenue($since, $courseIdList);

        $earningsByMonth = $months->map(function ($month) use ($paymentRows, $share) {
            $revenue = (float) ($paymentRows[$month] ?? 0);

            return [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'revenue' => round($revenue, 2),
                'earnings' => round($revenue * ($share / 100), 2),
            ];
        })->values();

        $recentEnrollments = $courseIds->isEmpty()
            ? collect()
            : CourseEnrollment::query()
                ->with(['student', 'course'])
                ->whereIn('course_id', $courseIds)
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(function (CourseEnrollment $enrollment) {
                    $student = $enrollment->student;
                    $name = $student
                        ? ($student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')))
                        : 'Student';

                    return [
                        'type' => 'enrollment',
                        'message' => trim($name) . ' enrolled in ' . ($enrollment->course->title ?? 'a course'),
                        'status' => $enrollment->status,
                        'at' => $enrollment->created_at?->toIso8601String(),
                    ];
                })
                ->values();

        $upcomingClasses = $courseIds->isEmpty()
            ? collect()
            : CourseMaterial::query()
                ->with('course')
                ->whereIn('course_id', $courseIds)
                ->where('type', 'zoom')
                ->orderByDesc('created_at')
                ->limit(6)
                ->get()
                ->map(fn (CourseMaterial $material) => [
                    'id' => $material->id,
                    'title' => $material->title,
                    'course_id' => $material->course_id,
                    'course_title' => $material->course->title ?? 'Course',
                    'meeting_id' => CourseMaterialHelper::meetingId($material),
                    'join_url' => null,
                    'embed_room_path' => CourseMaterialHelper::embedRoomPath($material, 0),
                    'host_room_path' => CourseMaterialHelper::embedRoomPath($material, 1),
                    'start_url' => null,
                    'scheduled_at' => CourseMaterialHelper::scheduledAt($material)?->toIso8601String(),
                    'created_at' => $material->created_at?->toIso8601String(),
                ])
                ->values();

        $quizCount = $courseIds->isEmpty()
            ? 0
            : CourseMaterial::query()
                ->whereIn('course_id', $courseIds)
                ->whereIn('type', ['quiz', 'assessment'])
                ->count();

        $payoutRequests = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (InstructorPayoutRequest $row) => [
                'id' => $row->id,
                'amount' => (float) $row->amount,
                'status' => $row->status,
                'payment_method' => $row->payment_method,
                'payment_method_label' => $row->payment_method_label,
                'payment_details' => $row->payment_details,
                'notes' => $row->notes,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->values();

        $uniqueStudents = $courseIds->isEmpty()
            ? 0
            : CourseEnrollment::whereIn('course_id', $courseIds)->distinct('student_id')->count('student_id');

        $totalEnrollments = $courseIds->isEmpty()
            ? 0
            : CourseEnrollment::whereIn('course_id', $courseIds)->count();

        $paidEnrollments = $courseIds->isEmpty()
            ? 0
            : CourseEnrollment::whereIn('course_id', $courseIds)->where('status', 'paid')->count();

        $materialsCount = $courseIds->isEmpty()
            ? 0
            : CourseMaterial::whereIn('course_id', $courseIds)->count();

        $activeCourses = $courses->filter(
            fn ($c) => strtolower((string) ($c['status'] ?? '')) === 'active'
        )->count();

        return response()->json([
            'instructor' => [
                'id' => $instructor->id,
                'name' => $instructor->name,
                'email' => $instructor->email,
                'status' => $instructor->status,
            ],
            'summary' => [
                'assignedCourses' => $courses->count(),
                'activeCourses' => $activeCourses,
                'totalStudents' => $uniqueStudents,
                'totalEnrollments' => $totalEnrollments,
                'paidEnrollments' => $paidEnrollments,
                'materialsCount' => $materialsCount,
                'quizCount' => $quizCount,
                'upcomingClasses' => $upcomingClasses->count(),
                'totalRevenue' => round($totalRevenue, 2),
                'totalEarnings' => $totalEarnings,
                'availableBalance' => $availableBalance,
                'pendingPayouts' => round((float) $pendingPayouts, 2),
                'paidOut' => round((float) $paidOut, 2),
                'instructorSharePercent' => $share,
            ],
            'courses' => $courses,
            'enrollmentsByMonth' => $enrollmentsByMonth,
            'earningsByMonth' => $earningsByMonth,
            'recentActivity' => $recentEnrollments,
            'upcomingClasses' => $upcomingClasses,
            'payoutRequests' => $payoutRequests,
        ], 200);
    }

    public function liveClasses(Request $request)
    {
        $email = $request->query('email');
        $courseId = $request->query('course_id');

        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $actor = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])
            ->first();

        if (!$actor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $role = strtolower(trim((string) ($actor->role ?? '')));

        if (in_array($role, ['admin', 'staff', 'partner_company'], true)) {
            return $this->liveClassesForPortalActor($request, $actor, $courseId);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);

        $courses = $instructor->assignedCourses()
            ->withCount([
                'enrollments as paid_enrollments_count' => fn ($q) => $q->whereIn('status', ['paid', 'completed']),
            ])
            ->orderBy('title')
            ->get()
            ->map(fn (Course $course) => $this->mapLiveClassCourse($course))
            ->values();

        return response()->json(
            $this->buildLiveClassesPayload($actor, $courses, $courseIds, $courseId),
            200
        );
    }

    private function mapLiveClassCourse(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'status' => $course->status,
            'duration' => $course->duration,
            'paid_enrollments_count' => (int) ($course->paid_enrollments_count ?? 0),
        ];
    }

    private function liveClassesForPortalActor(Request $request, User $actor, $courseId)
    {
        $tenantId = PlatformTenantScope::resolveTenantId($request);

        $coursesQuery = Course::query()
            ->withCount([
                'enrollments as paid_enrollments_count' => fn ($q) => $q->whereIn('status', ['paid', 'completed']),
            ])
            ->orderBy('title');

        if ($tenantId !== null) {
            $coursesQuery->where('platform_institution_id', $tenantId);
        }

        $courses = $coursesQuery
            ->get()
            ->map(fn (Course $course) => $this->mapLiveClassCourse($course))
            ->values();

        $courseIds = $courses->pluck('id');

        return response()->json(
            $this->buildLiveClassesPayload($actor, $courses, $courseIds, $courseId),
            200
        );
    }

    private function buildLiveClassesPayload(User $actor, $courses, $courseIds, $courseId): array
    {
        $sessionsQuery = CourseMaterial::query()
            ->with('course:id,title')
            ->where('type', 'zoom')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('created_at');

        if ($courseId) {
            $sessionsQuery->where('course_id', (int) $courseId);
        } elseif (!$courseIds->isEmpty()) {
            $sessionsQuery->whereIn('course_id', $courseIds);
        } else {
            $sessionsQuery->whereRaw('1 = 0');
        }

        $sessions = $sessionsQuery
            ->limit($courseId ? 20 : 12)
            ->get()
            ->map(fn (CourseMaterial $material) => [
                'id' => $material->id,
                'title' => $material->title,
                'course_id' => $material->course_id,
                'course_title' => $material->course?->title,
                'description' => $material->description,
                'meeting_id' => CourseMaterialHelper::meetingId($material),
                'join_url' => null,
                'embed_room_path' => CourseMaterialHelper::embedRoomPath($material, 0),
                'host_room_path' => CourseMaterialHelper::embedRoomPath($material, 1),
                'start_url' => null,
                'scheduled_at' => CourseMaterialHelper::scheduledAt($material)?->toIso8601String(),
                'created_at' => $material->created_at?->toIso8601String(),
            ])
            ->values();

        $zoomConfigured = !empty(config('services.zoom.account_id'))
            && !empty(config('services.zoom.client_id'))
            && !empty(config('services.zoom.client_secret'));

        return [
            'instructor' => [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
            ],
            'zoom' => [
                'configured' => $zoomConfigured,
                'host_user_id' => config('services.zoom.host_user_id', 'me'),
            ],
            'courses' => $courses,
            'sessions' => $sessions,
        ];
    }

    public function startLiveSession(Request $request, CourseMaterial $material)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'enable_recording' => 'nullable|boolean',
        ]);

        $instructor = $this->findLiveClassHost($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class session.'], 422);
        }

        if (!$this->canHostMaterial($instructor, $material)) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $enableRecording = (bool) ($data['enable_recording'] ?? false);
        $meetingId = CourseMaterialHelper::meetingId($material);
        $meta = is_array($material->metadata) ? $material->metadata : [];
        $recordingWarning = null;
        $recordingApiOk = false;

        if ($meetingId && $this->zoom->canManageMeetingViaApi($meetingId)) {
            $joinBeforeHost = (bool) ($meta['join_before_host'] ?? false);
            $settingsPatch = [
                'join_before_host' => $joinBeforeHost,
                'waiting_room' => (bool) ($meta['waiting_room'] ?? !$joinBeforeHost),
                'mute_upon_entry' => (bool) ($meta['mute_upon_entry'] ?? true),
            ];
            if ($enableRecording || !empty($meta['auto_recording'])) {
                $settingsPatch['auto_recording'] = 'cloud';
            }
            $this->zoom->updateMeetingSettings($meetingId, $settingsPatch);
        }

        if ($enableRecording) {
            $meta['recording_enabled'] = true;

            if ($meetingId && $this->zoom->canManageMeetingViaApi($meetingId)) {
                $result = $this->zoom->setMeetingAutoRecording($meetingId, true);
                if ($result === null) {
                    $recordingWarning = 'Cloud recording could not be enabled via Zoom API yet. It will be requested when you join the host room.';
                } elseif (!empty($result['error'])) {
                    $recordingWarning = (string) (data_get($result, 'body.message')
                        ?: 'Zoom rejected cloud recording for this meeting. Use Start recording in the host room after you join.');
                } else {
                    $recordingApiOk = true;
                }
            } elseif (!$meetingId) {
                $recordingWarning = 'No Zoom meeting ID on this session.';
            }

            $material->metadata = $meta;
            $material->save();
        }

        CourseMaterialHelper::markSessionStarted($material);

        $message = 'Live session marked as started. Learners can join now.';
        if ($enableRecording) {
            $message = $recordingApiOk
                ? 'Live session started with cloud recording enabled for paid learners.'
                : 'Live session started. ' . ($recordingWarning ?? 'Recording will be requested when you join the host room.');
        }

        return response()->json([
            'message' => $message,
            'recording_enabled' => (bool) ($meta['recording_enabled'] ?? false),
            'recording_warning' => $recordingWarning,
            'session' => CourseMaterialHelper::toLiveClassArray($material),
        ], 200);
    }

    public function liveClassLobby(Request $request, CourseMaterial $material)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        $host = $this->findLiveClassHost($data['instructor_email']);
        if (!$host) {
            return response()->json(['message' => 'Host account not found'], 404);
        }

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class session.'], 422);
        }

        if (!$this->canHostMaterial($host, $material)) {
            return response()->json(['message' => 'You are not authorized to view this live class lobby.'], 403);
        }

        return response()->json([
            'material_id' => $material->id,
            'course_title' => $material->course?->title,
            'session_title' => $material->title,
            'waiting' => $this->lobbyService->listForMaterial((int) $material->id),
            'waiting_count' => count($this->lobbyService->listForMaterial((int) $material->id)),
        ]);
    }

    public function dismissLobbyStudent(Request $request, CourseMaterial $material)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'student_id' => 'required|integer|min:1',
        ]);

        $host = $this->findLiveClassHost($data['instructor_email']);
        if (!$host) {
            return response()->json(['message' => 'Host account not found'], 404);
        }

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class session.'], 422);
        }

        if (!$this->canHostMaterial($host, $material)) {
            return response()->json(['message' => 'You are not authorized to change this live class lobby.'], 403);
        }

        $this->lobbyService->removeStudent((int) $material->id, (int) $data['student_id']);

        return response()->json([
            'ok' => true,
            'student_id' => (int) $data['student_id'],
            'waiting_count' => count($this->lobbyService->listForMaterial((int) $material->id)),
        ]);
    }

    public function setLiveClassAutoAdmit(Request $request, CourseMaterial $material)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'enabled' => 'required|boolean',
        ]);

        $host = $this->findLiveClassHost($data['instructor_email']);
        if (!$host) {
            return response()->json(['message' => 'Host account not found'], 404);
        }

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class session.'], 422);
        }

        if (!$this->canHostMaterial($host, $material)) {
            return response()->json(['message' => 'You are not authorized to change this live class.'], 403);
        }

        $enabled = (bool) $data['enabled'];
        $meta = is_array($material->metadata) ? $material->metadata : [];
        $meta['auto_admit'] = $enabled;

        $meetingId = trim((string) (CourseMaterialHelper::meetingId($material) ?? ''));
        if ($meetingId !== '' && $this->zoom->canManageMeetingViaApi($meetingId)) {
            if ($enabled) {
                $this->zoom->updateMeetingSettings($meetingId, [
                    'waiting_room' => false,
                    'join_before_host' => true,
                ]);
            } else {
                $joinBeforeHost = (bool) ($meta['join_before_host'] ?? false);
                $this->zoom->updateMeetingSettings($meetingId, [
                    'waiting_room' => (bool) ($meta['waiting_room'] ?? !$joinBeforeHost),
                    'join_before_host' => $joinBeforeHost,
                ]);
            }
        }

        $material->metadata = $meta;
        $material->save();

        return response()->json([
            'auto_admit' => $enabled,
            'message' => $enabled
                ? 'Auto-admit enabled. Learners can join the meeting without waiting for manual approval.'
                : 'Auto-admit disabled. Learners will wait in the waiting room until you admit them.',
            'session' => CourseMaterialHelper::toLiveClassArray($material),
        ]);
    }

    public function courseEnrolledStudents(Request $request, Course $course)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        $host = $this->findLiveClassHost($data['instructor_email']);
        if (!$host) {
            return response()->json(['message' => 'Host account not found'], 404);
        }

        if (!$this->canHostCourse($host, $course)) {
            return response()->json(['message' => 'You are not authorized to view enrollments for this course.'], 403);
        }

        $enrollments = CourseEnrollment::with(['student', 'studyShifts'])
            ->where('course_id', $course->id)
            ->get();

        $students = $enrollments->map(function ($enrollment) {
            $student = $enrollment->student;
            if (!$student) {
                return null;
            }

            return [
                'id' => $student->id,
                'first_name' => $student->first_name ?? null,
                'last_name' => $student->last_name ?? null,
                'name' => $student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),
                'email' => $student->email,
                'enrollment_status' => $enrollment->status,
                'study_shifts' => $this->formatStudyShifts($enrollment->studyShifts),
            ];
        })->filter()->values();

        $notifyableCount = $students->filter(
            fn ($s) => in_array(strtolower((string) ($s['enrollment_status'] ?? '')), ['paid', 'completed'], true)
        )->count();

        return response()->json([
            'students' => $students,
            'notifyable_count' => $notifyableCount,
        ]);
    }

    public function toggleLiveClassRecording(Request $request, CourseMaterial $material)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'action' => 'required|string|in:start,stop,pause,resume',
        ]);

        $instructor = $this->findLiveClassHost($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (strtolower((string) $material->type) !== 'zoom') {
            return response()->json(['message' => 'This material is not a live class session.'], 422);
        }

        if (!$this->canHostMaterial($instructor, $material)) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $meetingId = trim((string) (CourseMaterialHelper::meetingId($material) ?? ''));
        if ($meetingId === '') {
            return response()->json(['message' => 'No active Zoom meeting for this live class.'], 422);
        }

        $result = $this->zoom->setLiveRecordingStatus($meetingId, $data['action']);
        if ($result === null) {
            return response()->json(['message' => 'Zoom API is not configured.'], 422);
        }
        if (!empty($result['error'])) {
            $message = data_get($result, 'body.message', 'Zoom rejected the recording request.');
            if (stripos((string) $message, 'not recognized') !== false || (int) ($result['status'] ?? 0) === 404) {
                $message = 'Zoom recording control failed. Ensure Cloud Recording is enabled and your S2S app has meeting:write:admin scope.';
            }

            return response()->json([
                'message' => $message,
                'details' => $result['body'] ?? null,
            ], 422);
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        if ($data['action'] === 'start') {
            $meta['recording_enabled'] = true;
            $meta['recording_active'] = true;
        } elseif ($data['action'] === 'stop') {
            $meta['recording_active'] = false;
        }
        $material->metadata = $meta;
        $material->save();

        return response()->json([
            'message' => 'Recording ' . $data['action'] . ' request sent.',
            'recording_enabled' => (bool) ($meta['recording_enabled'] ?? false),
            'recording_active' => (bool) ($meta['recording_active'] ?? false),
            'result' => $result,
        ]);
    }

    public function students(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);
        if ($courseIds->isEmpty()) {
            return response()->json(['students' => [], 'courses' => [], 'study_shifts' => []], 200);
        }

        $courses = Course::query()
            ->whereIn('id', $courseIds)
            ->orderBy('title')
            ->get(['id', 'title']);

        $studyShiftId = $request->query('study_shift_id');

        $enrollmentQuery = CourseEnrollment::query()
            ->with(['student', 'course', 'studyShifts'])
            ->whereIn('course_id', $courseIds);

        if ($studyShiftId) {
            $enrollmentQuery->where(function ($q) use ($studyShiftId) {
                $q->whereHas('studyShifts', fn ($sub) => $sub->where('study_shifts.id', (int) $studyShiftId))
                    ->orWhere('study_shift_id', (int) $studyShiftId);
            });
        }

        $rows = $enrollmentQuery
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CourseEnrollment $enrollment) {
                $student = $enrollment->student;
                if (!$student) {
                    return null;
                }

                $studyShifts = $this->formatStudyShifts($enrollment->studyShifts);

                return [
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $student->id,
                    'name' => $student->name ?? trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? '')),
                    'email' => $student->email,
                    'country' => $student->country ?? null,
                    'course_id' => $enrollment->course_id,
                    'course_title' => $enrollment->course->title ?? 'Course',
                    'course_price' => (float) ($enrollment->course->price ?? 0),
                    'status' => $enrollment->status,
                    'payment_paid' => \App\Support\EnrollmentStatusHelper::isPaid($enrollment->status),
                    'has_access' => \App\Support\EnrollmentStatusHelper::hasCourseAccess($enrollment->status),
                    'enrolled_at' => $enrollment->created_at?->toIso8601String(),
                    'study_shifts' => $studyShifts,
                ];
            })
            ->filter()
            ->values();

        $availableShifts = StudyShift::query()
            ->whereIn('course_id', $courseIds)
            ->where('is_active', true)
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get()
            ->map(fn (StudyShift $shift) => $this->formatStudyShift($shift));

        return response()->json([
            'courses' => $courses,
            'students' => $rows,
            'study_shifts' => $availableShifts,
        ], 200);
    }

    private function formatStudyShifts($shifts): array
    {
        return collect($shifts)->map(fn (StudyShift $shift) => $this->formatStudyShift($shift))->values()->all();
    }

    private function formatStudyShift(StudyShift $shift): array
    {
        $dayNames = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
        $dayLabel = $dayNames[(int) $shift->day_of_week] ?? 'Day';
        $start = substr((string) $shift->start_time, 0, 5);
        $end = substr((string) $shift->end_time, 0, 5);

        return [
            'id' => $shift->id,
            'course_id' => $shift->course_id,
            'name' => $shift->name,
            'day_of_week' => (int) $shift->day_of_week,
            'day_label' => $dayLabel,
            'start_time' => $start,
            'end_time' => $end,
            'label' => sprintf('%s · %s %s–%s', $shift->name, $dayLabel, $start, $end),
        ];
    }

    public function createCourse(Request $request)
    {
        $data = $request->validate(array_merge([
            'instructor_email' => 'required|email',
            'program_id' => 'required|integer|exists:elearning_programs,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
        ], CourseDetailsHelper::validationRules()));

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $details = CourseDetailsHelper::extractFromRequest($request);
        $payload = [
            'program_id' => $data['program_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? 0,
            'duration' => $data['duration'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => 'Pending',
        ];
        CourseDetailsHelper::applyToPayload($payload, $details, $data['title']);

        $course = Course::create($payload);

        $instructor->assignedCourses()->syncWithoutDetaching([$course->id]);

        return response()->json([
            'message' => 'Course submitted for admin approval.',
            'course' => $course,
        ], 201);
    }

    public function updateCourse(Request $request, Course $course)
    {
        $data = $request->validate(array_merge([
            'instructor_email' => 'required|email',
            'program_id' => 'sometimes|required|integer|exists:elearning_programs,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
        ], CourseDetailsHelper::validationRules($course->id)));

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $course->id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $details = CourseDetailsHelper::extractFromRequest($request);

        $payload = [];
        foreach (['program_id', 'title', 'description', 'price', 'duration', 'requirements'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        CourseDetailsHelper::applyToPayload($payload, $details, $course->title ?? $data['title'] ?? null);

        $course->fill($payload);
        $course->save();

        \App\Support\ApiListCache::bump('courses');

        return response()->json([
            'message' => 'Course updated.',
            'course' => $course->fresh(),
        ], 200);
    }

    public function deleteCourse(Request $request, Course $course)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        if (!$instructor->assignedCourses()->where('courses.id', $course->id)->exists()) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        if ($course->enrollments()->exists()) {
            return response()->json([
                'message' => 'This course has enrolled learners and cannot be deleted. Contact an administrator.',
            ], 422);
        }

        $instructor->assignedCourses()->detach($course->id);
        $course->delete();

        \App\Support\ApiListCache::bump('courses');

        return response()->json(['message' => 'Course deleted.'], 200);
    }

    public function payoutPaymentOptions()
    {
        $options = collect(InstructorPayoutMethods::options())
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values();

        return response()->json(['paymentMethods' => $options], 200);
    }

    public function payoutRequests(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $rows = InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['payoutRequests' => $rows], 200);
    }

    public function requestPayout(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:' . implode(',', InstructorPayoutMethods::keys()),
            'payment_details' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $dashboard = $this->buildBalanceSnapshot($instructor);
        $available = $dashboard['availableBalance'];

        if ((float) $data['amount'] > $available) {
            return response()->json([
                'message' => 'Requested amount exceeds available balance ($' . number_format($available, 2) . ').',
            ], 422);
        }

        $row = InstructorPayoutRequest::create([
            'instructor_id' => $instructor->id,
            'amount' => round((float) $data['amount'], 2),
            'status' => 'pending',
            'payment_method' => $data['payment_method'],
            'payment_details' => $data['payment_details'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Payout request submitted. Admin will process it shortly.',
            'payoutRequest' => $row,
        ], 201);
    }

    public function quizzes(Request $request)
    {
        $email = $request->query('email');
        if (!$email) {
            return response()->json(['message' => 'Email is required'], 400);
        }

        $instructor = $this->findInstructor($email);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $courseIds = $this->courseIdsFor($instructor);
        if ($courseIds->isEmpty()) {
            return response()->json(['quizzes' => [], 'courses' => []], 200);
        }

        $courses = Course::query()->whereIn('id', $courseIds)->orderBy('title')->get(['id', 'title']);

        $quizzes = CourseMaterial::query()
            ->with('course')
            ->whereIn('course_id', $courseIds)
            ->whereIn('type', ['quiz', 'assessment'])
            ->orderByDesc('id')
            ->get()
            ->map(function (CourseMaterial $m) {
                $meta = is_array($m->metadata) ? $m->metadata : [];

                return [
                    'id' => $m->id,
                    'course_id' => $m->course_id,
                    'course_title' => $m->course->title ?? 'Course',
                    'title' => $m->title,
                    'description' => $m->description,
                    'topic' => $meta['topic'] ?? null,
                    'type' => $m->type,
                    'resource_url' => $m->resource_url,
                    'question_count' => count($meta['questions'] ?? []),
                    'passing_score' => (int) ($meta['passing_score'] ?? 70),
                    'time_limit_minutes' => QuizMaterialHelper::timeLimitMinutes($m),
                    'status' => QuizMaterialHelper::quizStatus($m),
                    'published_student_count' => count(QuizMaterialHelper::publishedStudentIds($m)),
                    'published_student_ids' => QuizMaterialHelper::publishedStudentIds($m),
                    'publish_to_all' => QuizMaterialHelper::isPublished($m) && empty(QuizMaterialHelper::publishedStudentIds($m)),
                    'ai_generated' => (bool) ($meta['ai_generated'] ?? false),
                    'created_at' => $m->created_at?->toIso8601String(),
                    'published_at' => $meta['published_at'] ?? null,
                ];
            })
            ->values();

        return response()->json([
            'courses' => $courses,
            'quizzes' => $quizzes,
        ], 200);
    }

    public function storeQuiz(Request $request)
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
            'course_id' => 'required|integer|exists:courses,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'resource_url' => 'nullable|url|max:2048',
        ]);

        $instructor = $this->findInstructor($data['instructor_email']);
        if (!$instructor) {
            return response()->json(['message' => 'Instructor not found'], 404);
        }

        $assigned = $instructor->assignedCourses()->where('courses.id', $data['course_id'])->exists();
        if (!$assigned) {
            return response()->json(['message' => 'You are not assigned to this course.'], 403);
        }

        $quiz = CourseMaterial::create([
            'course_id' => $data['course_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => 'quiz',
            'resource_url' => $data['resource_url'] ?? null,
            'sort_order' => 0,
        ]);

        return response()->json([
            'message' => 'Quiz created.',
            'quiz' => $quiz,
        ], 201);
    }

    private function buildBalanceSnapshot(User $instructor): array
    {
        $courseIds = $this->courseIdsFor($instructor);
        $share = $this->sharePercent();

        $totalRevenue = 0.0;
        if (!$courseIds->isEmpty()) {
            $courses = Course::whereIn('id', $courseIds)->get();
            foreach ($courses as $course) {
                $totalRevenue += $this->courseRevenue($course);
            }
        }

        $totalEarnings = round($totalRevenue * ($share / 100), 2);
        $paidOut = (float) InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['approved', 'paid', 'completed'])
            ->sum('amount');
        $pendingPayouts = (float) InstructorPayoutRequest::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        return [
            'totalEarnings' => $totalEarnings,
            'availableBalance' => max(0, round($totalEarnings - $paidOut - $pendingPayouts, 2)),
        ];
    }
}
