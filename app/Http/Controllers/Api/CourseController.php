<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Models\Student;
use App\Models\CourseMaterial;
use App\Models\StudyShift;
use App\Support\ApiListCache;
use Illuminate\Support\Facades\Log;
use App\Mail\CourseAppliedMail;
use App\Mail\CourseEnrollmentApprovedMail;
use App\Mail\CourseEnrollmentRejectedMail;
use App\Mail\CoursePaymentLinkMail;
use App\Mail\CourseClassScheduledMail;
use App\Mail\StaffClassScheduledMail;
use App\Services\MailDeliveryService;
use App\Services\ZoomService;
use App\Services\Meetings\MeetingProviderManager;
use App\Data\Meetings\MeetingCreateRequest;
use App\Enums\MeetingProvider;
use App\Models\PlatformInstitution;
use App\Exceptions\Meetings\MeetingCreationException;
use App\Exceptions\Meetings\ProviderNotConfiguredException;
use App\Support\CourseDetailsHelper;
use App\Support\CourseMaterialHelper;
use App\Support\EnrollmentStatusHelper;
use App\Support\InstructorLookup;
use App\Support\PlatformTenantScope;
use App\Support\ResolvesEnrollmentStaff;
use App\Services\CourseCodeGenerator;
use Carbon\Carbon;

class CourseController extends Controller
{
    use ResolvesEnrollmentStaff;

    protected ZoomService $zoom;

    protected MailDeliveryService $mail;

    protected MeetingProviderManager $meetingProviders;

    public function __construct(ZoomService $zoom, MailDeliveryService $mail, MeetingProviderManager $meetingProviders)
    {
        $this->zoom = $zoom;
        $this->mail = $mail;
        $this->meetingProviders = $meetingProviders;
    }
    public function index(Request $request)
    {
        // Assigned courses should not remain Pending in Manage Courses lists.
        $healed = Course::query()
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'pending'")
            ->whereHas('instructors')
            ->update(['status' => 'Active']);
        if ($healed > 0) {
            ApiListCache::bump('courses');
            ApiListCache::bump('instructors');
        }

        $programId = $request->query('program_id');
        $tenantId = PlatformTenantScope::resolveTenantId($request);
        $cacheKey = 'owned_' . ($tenantId !== null ? 'inst_' . $tenantId : 'hub')
            . ($programId ? '_program_' . $programId : '_all');

        $courses = ApiListCache::remember('courses', $cacheKey, 120, function () use ($request, $programId) {
            $query = Course::with([
                'program:id,name',
                'instructors:id,name,email,role',
            ])->orderByDesc('id');
            PlatformTenantScope::applyToQuery($query, $request);

            if ($programId) {
                $query->where('program_id', $programId);
            }

            return $query->get();
        });

        return response()->json($courses, 200);
    }

    public function suggestCode(Request $request)
    {
        $title = (string) $request->query('title', '');
        $prefix = $request->query('prefix');

        return response()->json([
            'course_code' => CourseCodeGenerator::generate($prefix, $title),
            'prefixes' => CourseCodeGenerator::PREFIXES,
        ], 200);
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge([
            'program_id' => 'required|integer|exists:elearning_programs,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'image' => 'nullable',
        ], CourseDetailsHelper::validationRules()));

        $program = \App\Models\ElearningProgram::query()->findOrFail($data['program_id']);
        PlatformTenantScope::assertCanAccess($request, $program);

        $details = CourseDetailsHelper::extractFromRequest($request);

        // New courses go live as Active (ignore client "Pending") so assign + My Courses work immediately.
        $requestedStatus = trim((string) ($data['status'] ?? 'Active'));
        $status = strcasecmp($requestedStatus, 'Pending') === 0 || $requestedStatus === ''
            ? 'Active'
            : $requestedStatus;

        $payload = [
            'program_id' => $data['program_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'] ?? null,
            'duration' => $data['duration'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => $status,
        ];

        CourseDetailsHelper::applyToPayload($payload, $details, $data['title']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $payload['image'] = asset('storage/' . $path);
        }

        PlatformTenantScope::stampInstitutionId($request, $payload);

        $course = Course::create($payload);

        // Auto-assign the creating teachable actor as the sole teacher.
        $actor = PlatformTenantScope::resolveActorUser($request);
        if ($actor && InstructorLookup::isTeachable($actor)) {
            $course->instructors()->sync([(int) $actor->id]);
            if (strcasecmp((string) $course->status, 'Pending') === 0) {
                $course->status = 'Active';
                $course->save();
            }
        }

        $this->bumpCourseCaches();

        return response()->json([
            'message' => $actor && InstructorLookup::isTeachable($actor)
                ? 'Course created and assigned to you.'
                : 'Course created. Use Assign to link a teacher when ready.',
            'course' => $course->load('instructors:id,name,email,role'),
        ], 201);
    }

    public function update(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);
        $data = $request->validate(array_merge([
            'program_id' => 'sometimes|required|integer|exists:elearning_programs,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric',
            'duration' => 'nullable|string|max:255',
            'requirements' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'image' => 'nullable',
        ], CourseDetailsHelper::validationRules($course->id)));

        $details = CourseDetailsHelper::extractFromRequest($request);

        $updateData = $data;
        unset($updateData['image'], $updateData['auto_generate_code'], $updateData['code_prefix']);

        $detailsPayload = [];
        CourseDetailsHelper::applyToPayload($detailsPayload, $details, $course->title ?? $data['title'] ?? null);

        $course->fill(array_merge($updateData, $detailsPayload));

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('uploads', 'public');
            $course->image = asset('storage/' . $path);
        }

        $course->save();

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course updated',
            'course' => $course,
        ]);
    }

    public function destroy(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);
        $course->delete();
        $this->bumpCourseCaches();

        return response()->json(['message' => 'Course deleted']);
    }

    public function assignToUser(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        if (!InstructorLookup::isTeachable($user)) {
            return response()->json([
                'message' => 'Selected user cannot be assigned as a course teacher.',
            ], 422);
        }

        $tenantId = PlatformTenantScope::resolvePartnerTenantId($request);
        if ($tenantId !== null && (int) $user->platform_institution_id !== (int) $tenantId) {
            return response()->json([
                'message' => 'Teacher must belong to your institution.',
            ], 422);
        }

        $existingIds = $course->instructors()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $alreadyAssignedToThisUser = in_array((int) $user->id, $existingIds, true);
        $otherAssignees = array_values(array_filter($existingIds, fn ($id) => (int) $id !== (int) $user->id));

        if (!$alreadyAssignedToThisUser && count($otherAssignees) > 0) {
            $current = $course->instructors()
                ->select(['users.id', 'users.name', 'users.email', 'users.role'])
                ->get();

            return response()->json([
                'message' => 'This course is already assigned to one person. Unassign them first, then assign someone new.',
                'assigned' => $current,
            ], 422);
        }

        if (!$alreadyAssignedToThisUser) {
            // Exclusive: one teacher per course.
            $course->instructors()->sync([(int) $user->id]);
        }

        // Assigned courses must be Active (Manage Courses / My Courses were stuck on Pending).
        $course->refresh();
        if (strcasecmp(trim((string) ($course->status ?? '')), 'Pending') === 0) {
            $course->status = 'Active';
            $course->save();
        }

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course assigned to teacher',
            'course' => $course->fresh(['instructors:id,name,email,role']),
        ]);
    }

    public function unassignFromUser(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        if (!InstructorLookup::isTeachable($user)) {
            return response()->json([
                'message' => 'Selected user is not a course teacher.',
            ], 422);
        }

        $user->assignedCourses()->detach($course->id);

        $this->bumpCourseCaches();

        return response()->json([
            'message' => 'Course unassigned from instructor',
        ]);
    }

    protected function bumpCourseCaches(): void
    {
        ApiListCache::bump('courses');
        ApiListCache::bump('elearning_programs');
        ApiListCache::bump('instructors');
        ApiListCache::bump('analytics');
    }

    /**
     * Send course-related mail with institution branding when the course belongs to a partner.
     */
    protected function sendCourseMail(Course $course, string $to, $mailable, array $context = []): bool
    {
        $institutionId = (int) ($course->platform_institution_id ?? 0);
        if ($institutionId > 0) {
            return $this->mail->sendToForInstitution($institutionId, $to, $mailable, $context);
        }

        return $this->mail->sendTo($to, $mailable, $context);
    }

    public function enroll(Request $request, Course $course)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'level' => 'nullable|string|max:255',
            'study_shift_id' => 'nullable|integer|exists:study_shifts,id',
            'study_shift_ids' => 'nullable|array',
            'study_shift_ids.*' => 'integer|exists:study_shifts,id',
            'auto_approve' => 'nullable|boolean',
        ]);

        $student = Student::findOrFail($data['student_id']);
        PlatformTenantScope::assertStudentCanAccessCourse($student, $course);

        $shiftIds = array_values(array_unique(array_filter(
            array_map('intval', $data['study_shift_ids'] ?? [])
        )));

        if ($shiftIds === [] && !empty($data['study_shift_id'])) {
            $shiftIds = [(int) $data['study_shift_id']];
        }

        $shiftsResult = $this->resolveEnrollmentStudyShifts($course, $shiftIds);
        if ($shiftsResult instanceof \Illuminate\Http\JsonResponse) {
            return $shiftsResult;
        }
        $studyShifts = $shiftsResult;

        // Check if the student is already enrolled in this course
        $existing = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'You have already applied for this course.',
                'enrollment' => $existing,
            ], 200);
        }

        $autoApprove = (bool) ($data['auto_approve'] ?? false);

        $enrollment = CourseEnrollment::create([
            'student_id' => $data['student_id'],
            'course_id' => $course->id,
            'status' => $autoApprove ? 'approved' : 'enrolled',
            'level' => $data['level'] ?? null,
            'study_shift_id' => $studyShifts !== [] ? $studyShifts[0]->id : null,
        ]);

        if ($studyShifts !== []) {
            $enrollment->studyShifts()->sync(collect($studyShifts)->pluck('id')->all());
        }

        $enrollment->load('studyShifts');

        if ($student && $student->email) {
            if ($autoApprove) {
                $this->sendCourseMail($course, 
                    $student->email,
                    new CourseEnrollmentApprovedMail($student, $course),
                    ['event' => 'enrollment_approved', 'student_id' => $data['student_id'], 'course_id' => $course->id]
                );
            } else {
                $this->sendCourseMail($course, 
                    $student->email,
                    new CourseAppliedMail($student, $course, $enrollment->level),
                    ['event' => 'course_applied', 'student_id' => $data['student_id'], 'course_id' => $course->id]
                );
            }
        }

        return response()->json([
            'message' => $autoApprove
                ? 'Student enrolled with full course access. Payment can be collected later.'
                : 'Enrolled successfully',
            'enrollment' => array_merge($enrollment->toArray(), [
                'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
            ]),
        ], 201);
    }

    /**
     * Admin or instructor approves a learner's course application — learner gets full access immediately.
     */
    public function approveEnrollment(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if ($enrollment->status === 'rejected') {
            return response()->json([
                'message' => 'This enrollment was rejected and cannot be approved.',
            ], 422);
        }

        if (in_array($enrollment->status, ['paid', 'completed'], true)) {
            return response()->json([
                'message' => 'This enrollment is already active.',
            ], 422);
        }

        if ($enrollment->status === 'approved') {
            return response()->json([
                'message' => 'Enrollment is already approved. The learner has course access.',
                'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
            ], 200);
        }

        if (!EnrollmentStatusHelper::isPendingApproval($enrollment->status)) {
            return response()->json([
                'message' => 'Only pending applications can be approved.',
            ], 422);
        }

        $enrollment->status = 'approved';
        $enrollment->save();

        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            $this->sendCourseMail($course, 
                $student->email,
                new CourseEnrollmentApprovedMail($student, $course),
                ['event' => 'enrollment_approved', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => 'Enrollment approved. The learner now has full access to course materials.',
            'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
        ]);
    }

    public function scheduleClass(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);

        $data = $request->validate([
            'start_time' => 'required|date',
            'instructor_email' => 'nullable|email',
            'topic' => 'nullable|string|max:255',
            'duration' => 'nullable|integer|min:15|max:480',
            'timezone' => 'nullable|string|max:64',
            'zoom_link' => 'nullable|url',
            'notes' => 'nullable|string',
            'staff_id' => 'nullable|exists:users,id',
            'notify_only' => 'nullable|boolean',
            'join_before_host' => 'nullable|boolean',
            'mute_upon_entry' => 'nullable|boolean',
            'auto_recording' => 'nullable|boolean',
        ]);

        $instructor = null;
        if (!empty($data['instructor_email'])) {
            $instructor = InstructorLookup::byEmail($data['instructor_email']);

            if (!$instructor) {
                return response()->json(['message' => 'Instructor not found.'], 404);
            }

            $isAssigned = $instructor->assignedCourses()->where('courses.id', $course->id)->exists();
            if (!$isAssigned) {
                return response()->json([
                    'message' => 'You are not assigned to this course. Assign it to yourself in Course Management before scheduling a live class.',
                ], 403);
            }
        }

        $timezone = trim((string) ($data['timezone'] ?? '')) ?: config('app.timezone', 'UTC');
        try {
            $scheduledAt = Carbon::parse($data['start_time'], $timezone);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid start time. Choose a valid date and time.'], 422);
        }

        if ($scheduledAt->lte(now())) {
            return response()->json(['message' => 'Scheduled time must be in the future.'], 422);
        }

        $zoomJoinLink = $data['zoom_link'] ?? null;
        $zoomStartUrl = null;
        $zoomMeetingId = null;
        $zoomPassword = null;
        $joinPwd = null;
        $recordingFallback = false;
        $institutionId = $course->platform_institution_id ? (int) $course->platform_institution_id : null;
        $hostId = null;
        $actorUserId = $instructor?->id ? (int) $instructor->id : null;
        $actorEmail = $instructor?->email ?? ($data['instructor_email'] ?? null);
        $meetingProviderValue = MeetingProvider::Daily->value;
        $dailyRoomMeta = [];

        if (!$zoomJoinLink) {
            $institution = $institutionId ? PlatformInstitution::query()->find($institutionId) : null;
            $provider = $this->meetingProviders->forInstitution($institution);
            $meetingProviderValue = $provider->provider()->value;
            $topic = trim((string) ($data['topic'] ?? '')) ?: ($course->title ?? 'Course Class');

            try {
                $result = $provider->createMeeting(new MeetingCreateRequest(
                    topic: $topic,
                    startAt: $scheduledAt,
                    durationMinutes: (int) ($data['duration'] ?? 60),
                    timezone: $timezone,
                    platformInstitutionId: $institutionId,
                    actorUserId: $actorUserId,
                    actorEmail: $actorEmail,
                    agenda: $data['notes'] ?? '',
                    joinBeforeHost: (bool) ($data['join_before_host'] ?? false),
                    waitingRoom: !((bool) ($data['join_before_host'] ?? false)),
                    muteUponEntry: (bool) ($data['mute_upon_entry'] ?? true),
                    autoRecording: (bool) ($data['auto_recording'] ?? false),
                    courseId: (int) $course->id,
                ));
            } catch (ProviderNotConfiguredException $e) {
                return response()->json(['message' => $e->getMessage()], 503);
            } catch (MeetingCreationException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $recordingFallback = $result->recordingFallback;
            $zoomJoinLink = $result->meetingUrl;
            $zoomStartUrl = $result->hostUrl;
            $zoomMeetingId = $result->externalMeetingId;
            $zoomPassword = $result->password;
            $joinPwd = $result->metadata['join_pwd'] ?? $this->zoom->extractPasswordFromJoinUrl($zoomJoinLink);
            $hostId = $result->metadata['zoom_host_user_id'] ?? null;
            $dailyRoomMeta = $result->metadata;

            if (!$zoomJoinLink) {
                return response()->json([
                    'message' => 'Meeting was created but join link was not returned.',
                ], 500);
            }
        }

        $staff = null;
        if (!empty($data['staff_id'])) {
            $staff = User::find($data['staff_id']);
        } elseif ($instructor) {
            $staff = $instructor;
        } elseif ($request->user()) {
            $staff = $request->user();
        }

        $notifyOnly = !empty($data['notify_only']);
        $material = null;
        $recordingEnabled = (bool) ($data['auto_recording'] ?? false) && empty($recordingFallback);
        if (!$notifyOnly) {
            $materialTitle = trim((string) ($data['topic'] ?? '')) ?: ('Live class - ' . $scheduledAt->copy()->timezone($timezone)->format('M j, Y g:i A'));
            if ($joinPwd === null && is_string($zoomJoinLink)) {
                $joinPwd = $this->zoom->extractPasswordFromJoinUrl($zoomJoinLink);
            }

            try {
                $material = CourseMaterial::create([
                    'course_id' => $course->id,
                    'title' => $materialTitle,
                    'description' => $data['notes'] ?? null,
                    'type' => $meetingProviderValue === MeetingProvider::Daily->value ? 'daily' : 'zoom',
                    'resource_url' => $zoomJoinLink,
                    'scheduled_at' => $scheduledAt,
                    'metadata' => array_filter([
                        'meeting_provider' => $meetingProviderValue,
                        'join_url' => $zoomJoinLink,
                        'start_url' => $zoomStartUrl,
                        'meeting_id' => $zoomMeetingId,
                        'password' => $zoomPassword,
                        'join_pwd' => $joinPwd,
                        'zoom_host_user_id' => $hostId ?? null,
                        'host_user_id' => $actorUserId,
                        'daily_room_name' => $dailyRoomMeta['daily_room_name'] ?? null,
                        'daily_room_url' => $dailyRoomMeta['daily_room_url'] ?? null,
                        'platform_institution_id' => $institutionId,
                        'duration' => $data['duration'] ?? 60,
                        'timezone' => $timezone,
                        'auto_recording' => $recordingEnabled,
                        'recording_enabled' => $recordingEnabled,
                        'join_before_host' => (bool) ($data['join_before_host'] ?? false),
                        'waiting_room' => !((bool) ($data['join_before_host'] ?? false)),
                        'mute_upon_entry' => (bool) ($data['mute_upon_entry'] ?? true),
                    ], static fn ($v) => $v !== null),
                    'sort_order' => 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to save scheduled live class material', [
                    'course_id' => $course->id,
                    'zoom_meeting_id' => $zoomMeetingId,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Zoom meeting was created but saving the class failed. Run php artisan migrate on the server, then try again.',
                ], 500);
            }
        }

        $hostRoomUrl = $material ? CourseMaterialHelper::embedRoomUrl($material, 1) : null;
        $hostRoomPath = $material ? CourseMaterialHelper::embedRoomPath($material, 1) : null;
        $learnerPortalUrl = CourseMaterialHelper::learnerPortalUrl();

        if ($staff && !empty($staff->email)) {
            $this->sendCourseMail($course, 
                $staff->email,
                new StaffClassScheduledMail(
                    $staff,
                    $course,
                    $scheduledAt->toIso8601String(),
                    $hostRoomUrl ?? $learnerPortalUrl,
                    $data['notes'] ?? null,
                    $hostRoomUrl,
                    CourseMaterialHelper::instructorClassesUrl(),
                ),
                ['event' => 'staff_class_scheduled', 'course_id' => $course->id, 'staff_id' => $staff->id]
            );
        }

        $notifiedCount = 0;
        $learners = CourseEnrollment::query()
            ->with('student')
            ->where('course_id', $course->id)
            ->whereIn('status', ['paid', 'completed'])
            ->get();

        foreach ($learners as $enrollment) {
            $student = $enrollment->student;
            if (!$student || empty($student->email)) {
                continue;
            }

            $studentJoinUrl = $material
                ? CourseMaterialHelper::embedRoomUrl($material, 0, (int) $student->id)
                : $learnerPortalUrl;

            if ($this->sendCourseMail($course, 
                $student->email,
                new CourseClassScheduledMail(
                    $student,
                    $course,
                    $scheduledAt->toIso8601String(),
                    $studentJoinUrl ?? $learnerPortalUrl,
                    $data['notes'] ?? null,
                    $learnerPortalUrl,
                ),
                ['event' => 'learner_class_scheduled', 'course_id' => $course->id, 'student_id' => $student->id]
            )) {
                $notifiedCount++;
            }
        }

        $message = 'Class scheduled via Zoom API. Learners have been notified where possible.';
        if (!empty($recordingFallback)) {
            $message .= ' Cloud recording was unavailable on your Zoom plan, so the meeting was saved without automatic recording.';
        }

        return response()->json([
            'message' => $message,
            'recording_fallback' => !empty($recordingFallback),
            'host_room_url' => $hostRoomUrl,
            'host_room_path' => $hostRoomPath,
            'learner_portal_url' => $learnerPortalUrl,
            'zoom_meeting_id' => $zoomMeetingId,
            'students_notified' => $notifiedCount,
            'material' => $material ? CourseMaterialHelper::toLiveClassArray($material) : null,
        ]);
    }

    public function enrolledStudents(Request $request, Course $course)
    {
        PlatformTenantScope::assertCanAccess($request, $course);

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
                'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
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

    public function markPaid(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if (!in_array($enrollment->status, ['approved', 'paid'], true)) {
            return response()->json([
                'message' => 'Enrollment must be approved before payment can be recorded.',
            ], 422);
        }

        $enrollment->status = 'paid';
        $enrollment->save();

        return response()->json([
            'message' => 'Enrollment marked as paid.',
            'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
        ]);
    }

    public function rejectEnrollment(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'nullable|string|max:2000',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        $enrollment->status = 'rejected';
        $enrollment->save();

        $student = Student::find($data['student_id']);
        if ($student && $student->email) {
            $this->sendCourseMail($course, 
                $student->email,
                new CourseEnrollmentRejectedMail($student, $course, $data['reason'] ?? null),
                ['event' => 'enrollment_rejected', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => 'Enrollment rejected.',
            'enrollment' => $this->formatEnrollmentForApi($enrollment->fresh('studyShifts'), $course),
        ]);
    }

    /**
     * Remove a learner from a course (e.g. refused to pay). Not allowed for paid/completed enrollments.
     */
    public function removeEnrollment(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'nullable|string|max:2000',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if (EnrollmentStatusHelper::isPaid($enrollment->status)) {
            return response()->json([
                'message' => 'Paid enrollments cannot be removed. Contact an administrator if a refund is required.',
            ], 422);
        }

        $enrollment->studyShiftLinks()->delete();
        $enrollment->studyShifts()->detach();
        $enrollment->delete();

        return response()->json([
            'message' => 'Learner removed from this course.',
        ]);
    }

    /**
     * Create a Stripe checkout link and optionally email it to the learner.
     */
    public function sendPaymentLink(Request $request, Course $course)
    {
        if ($denied = $this->assertCanManageCourseEnrollment($request, $course)) {
            return $denied;
        }

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'send_email' => 'nullable|boolean',
            'email' => 'nullable|email',
            'instructor_email' => 'nullable|email',
        ]);

        $enrollment = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return response()->json([
                'message' => 'Enrollment not found for this student and course.',
            ], 404);
        }

        if (!EnrollmentStatusHelper::canPay($enrollment->status)) {
            return response()->json([
                'message' => 'Payment links can only be sent for approved enrollments that are not yet paid.',
            ], 422);
        }

        $stripe = app(\App\Services\StripePaymentService::class);
        $result = $stripe->createCheckoutSession($course, (int) $data['student_id']);

        if (empty($result['ok'])) {
            return response()->json([
                'message' => $result['message'] ?? 'Unable to create payment link.',
            ], $result['status'] ?? 500);
        }

        $paymentUrl = $result['url'];
        $student = Student::find($data['student_id']);
        $sendEmail = $data['send_email'] ?? true;

        if ($sendEmail && $student?->email) {
            $this->sendCourseMail($course, 
                $student->email,
                new CoursePaymentLinkMail(
                    $student,
                    $course,
                    $paymentUrl,
                    (float) ($course->price ?? 0)
                ),
                ['event' => 'payment_link_sent', 'student_id' => $data['student_id'], 'course_id' => $course->id]
            );
        }

        return response()->json([
            'message' => $sendEmail && $student?->email
                ? 'Payment link sent to the learner by email.'
                : 'Payment link created.',
            'payment_url' => $paymentUrl,
        ]);
    }

    public function studentEnrollments(Student $student)
    {
        $enrollments = CourseEnrollment::with(['studyShifts', 'course:id,title,price'])
            ->where('student_id', $student->id)
            ->get();

        return response()->json([
            'enrollments' => $enrollments->map(
                fn (CourseEnrollment $enrollment) => $this->formatEnrollmentForApi($enrollment, $enrollment->course)
            ),
        ]);
    }

    private function formatEnrollmentForApi(CourseEnrollment $enrollment, ?Course $course = null): array
    {
        $course ??= $enrollment->course;
        $status = (string) ($enrollment->status ?? 'enrolled');

        return [
            'enrollment_id' => (int) $enrollment->id,
            'course_id' => (int) $enrollment->course_id,
            'course_title' => $course?->title,
            'course_price' => (float) ($course?->price ?? 0),
            'status' => $status,
            'payment_paid' => EnrollmentStatusHelper::isPaid($status),
            'has_access' => EnrollmentStatusHelper::hasCourseAccess($status),
            'level' => $enrollment->level,
            'study_shifts' => $this->formatStudyShiftsForApi($enrollment->studyShifts),
        ];
    }

    private const STUDY_SHIFT_DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * @return array<int, StudyShift>|\Illuminate\Http\JsonResponse
     */
    private function resolveEnrollmentStudyShifts(Course $course, array $shiftIds)
    {
        $service = app(\App\Services\EnrollmentStudyShiftService::class);

        return $service->resolveStudyShiftsForCourse($course, $shiftIds, null, true);
    }

    private function formatStudyShiftsForApi($shifts): array
    {
        return collect($shifts)->map(function (StudyShift $shift) {
            $dayLabel = self::STUDY_SHIFT_DAY_NAMES[(int) $shift->day_of_week] ?? 'Day';
            $start = substr((string) $shift->start_time, 0, 5);
            $end = substr((string) $shift->end_time, 0, 5);

            return [
                'id' => $shift->id,
                'name' => $shift->name,
                'day_of_week' => (int) $shift->day_of_week,
                'day_label' => $dayLabel,
                'start_time' => $start,
                'end_time' => $end,
                'label' => sprintf('%s · %s %s–%s', $shift->name, $dayLabel, $start, $end),
            ];
        })->values()->all();
    }
}
