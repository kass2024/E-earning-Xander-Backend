<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\CourseMaterial;
use App\Models\Student;
use App\Models\User;
use App\Models\WebinarSetting;
use App\Services\LiveClassLobbyService;
use App\Services\Meetings\LiveMeetingJoinService;
use App\Services\Meetings\AdminZoomMeetingJoinService;
use App\Services\Meetings\WebinarDailyService;
use App\Services\ZoomMeetingSdkService;
use App\Services\ZoomService;
use App\Support\CourseMaterialHelper;
use App\Support\EnrollmentStatusHelper;
use App\Support\FrontendUrl;
use App\Support\PlatformInstitutionHelper;
use App\Support\PlatformTenantScope;
use App\Support\ZoomMeetingBrandingResolver;
use App\Models\PlatformInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ZoomEmbedController extends Controller
{
    public function __construct(
        protected ZoomMeetingSdkService $sdkService,
        protected ZoomService $zoomService,
        protected ZoomMeetingBrandingResolver $brandingResolver,
        protected LiveClassLobbyService $lobbyService,
        protected LiveMeetingJoinService $liveMeetingJoin,
        protected AdminZoomMeetingJoinService $adminZoomJoin,
        protected WebinarDailyService $webinarDaily,
    ) {
    }

    public function config(): JsonResponse
    {
        $embed = $this->sdkService->configurationStatus();
        $api = $this->zoomService->configurationStatus();

        return response()->json([
            'embed_enabled' => $embed['embed_ready'],
            'sdk_key' => $embed['embed_ready'] ? config('services.zoom.sdk_key') : null,
            'sdk_key_preview' => $embed['sdk_key_preview'] ?? null,
            'api_ready' => $api['api_ready'],
            'host_user_id' => $api['host_user_id'] ?? null,
            'frontend_base' => FrontendUrl::base(),
            'platforms' => ['web', 'android'],
        ]);
    }

    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'material_id' => 'nullable|integer|exists:course_materials,id',
            'meeting_number' => 'nullable|string|max:120',
            'user_name' => 'nullable|string|max:120',
            'role' => 'nullable|integer|in:0,1',
            'password' => 'nullable|string|max:64',
            'instructor_email' => 'nullable|email',
            'user_email' => 'nullable|email',
            'platform_institution_id' => 'nullable|integer',
            'student_id' => 'nullable|integer|exists:students,id',
            'webinar_host' => 'nullable|boolean',
        ]);

        $role = (int) ($data['role'] ?? 0);

        if (!empty($data['material_id'])) {
            return $this->materialAuth(
                CourseMaterial::query()->findOrFail((int) $data['material_id']),
                $role,
                $data
            );
        }

        if (!empty($data['webinar_host'])) {
            return $this->buildWebinarHostAuth($data);
        }

        $rawMeetingNumber = trim((string) ($data['meeting_number'] ?? ''));

        $actorEmail = trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? ''));
        $actorEmail = $actorEmail !== '' ? $actorEmail : null;

        $userName = trim((string) ($data['user_name'] ?? ''));
        // Invite links set user_email — use that as the guest display name instead of generic "Guest".
        if ($userName === '' || strcasecmp($userName, 'Guest') === 0) {
            if ($role !== 1 && $actorEmail) {
                $userName = $actorEmail;
            } elseif ($userName === '') {
                $userName = $role === 1 ? 'Host' : 'Guest';
            }
        }
        $sanctumUser = $request->user();
        if ($actorEmail === null && $sanctumUser && !empty($sanctumUser->email)) {
            $actorEmail = trim((string) $sanctumUser->email) ?: null;
        }
        $actorUser = $actorEmail
            ? User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])->first()
            : ($sanctumUser ?: null);
        $platformInstitutionId = $role === 1
            ? $this->resolveHostTenantInstitutionId($actorUser instanceof User ? $actorUser : null, $data, null)
            : (isset($data['platform_institution_id']) ? (int) $data['platform_institution_id'] : null);

        $trustedOwner = $this->resolveTrustedOwner($sanctumUser, $actorUser instanceof User ? $actorUser : null);

        $adminMeeting = $rawMeetingNumber !== ''
            ? $this->adminZoomJoin->findByRoomName($rawMeetingNumber)
            : null;
        if ($adminMeeting && $this->adminZoomJoin->isDailyMeeting($adminMeeting)) {
            try {
                $dailySdk = $this->adminZoomJoin->buildSdkPayload(
                    $adminMeeting,
                    $userName,
                    $actorEmail ?: ('guest-' . substr(md5($userName . microtime(true)), 0, 10)),
                    $trustedOwner && $role === 1,
                );
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $brandingInstitutionId = $this->adminMeetingBrandingInstitutionId($adminMeeting, $actorUser instanceof User ? $actorUser : null, $role);
            $branding = $this->meetingBrandingPayload(
                $actorEmail,
                $brandingInstitutionId,
                false,
            );
            $branding['session_title'] = trim((string) ($adminMeeting->topic ?? '')) ?: 'Meeting';
            $branding['meeting_mode'] = strtolower(trim((string) (
                $adminMeeting->meeting_mode
                ?? $adminMeeting->meta['meeting_mode']
                ?? $adminMeeting->meta['type']
                ?? 'meeting'
            )));
            if ($trustedOwner && $role === 1) {
                $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
                    $brandingInstitutionId,
                    $actorUser instanceof User && $actorUser->id ? (int) $actorUser->id : null,
                    $actorEmail,
                );
                $branding = $this->brandingResolver->finalizeHostSdkBranding(
                    $branding,
                    $zoomHost,
                    $actorUser instanceof User ? $actorUser : null,
                );
                $dailySdk['user_name'] = $this->hostOrgDisplayName($branding);
            }

            return response()->json(array_merge([
                'provider' => 'daily',
                'sdk' => $dailySdk,
            ], $branding));
        }

        // Daily room names are alphanumeric; Zoom IDs are numeric-only.
        $dailyRoom = $this->webinarDaily->isDailyRoomName($rawMeetingNumber)
            ? $rawMeetingNumber
            : null;
        $digitsOnly = preg_replace('/\D+/', '', $rawMeetingNumber);
        $meetingNumber = $dailyRoom ?? (preg_match('/^\d{9,15}$/', $digitsOnly) ? $digitsOnly : null);
        if ($meetingNumber === '' || $meetingNumber === null) {
            return response()->json(['message' => 'Provide material_id, meeting_number, or webinar_host.'], 422);
        }

        if ($dailyRoom) {
            // Prefer the institution's webinar settings row that owns this room (never overwrite hub singleton).
            $settings = WebinarSetting::query()
                ->where('zoom_meeting_id', $dailyRoom)
                ->first();
            if (!$settings) {
                // Do not bind unknown rooms onto hub/actor settings for guests — that corrupts tenant rooms.
                return response()->json([
                    'message' => 'This webinar room is not registered. Ask the host to start the session first.',
                ], 404);
            }
            try {
                // Never trust client role=1 for Daily owner tokens.
                $dailySdk = $this->webinarDaily->buildSdkPayload(
                    $settings,
                    $userName,
                    $actorEmail ?: ('guest-' . substr(md5($userName . microtime(true)), 0, 10)),
                    $trustedOwner && $role === 1,
                );
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $brandingInstitutionId = $settings->platform_institution_id
                ? (int) $settings->platform_institution_id
                : ($this->isHubScopedDailyRoom($dailyRoom) ? null : $platformInstitutionId);
            if ($trustedOwner && $role === 1 && $actorUser instanceof User && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
                $brandingInstitutionId = null;
            }
            $branding = $this->meetingBrandingPayload(
                $actorEmail,
                $brandingInstitutionId,
                false,
            );
            $branding['session_title'] = 'Webinar';
            $branding['meeting_mode'] = 'webinar';
            if ($trustedOwner && $role === 1) {
                $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
                    $brandingInstitutionId,
                    $actorUser instanceof User && $actorUser->id ? (int) $actorUser->id : null,
                    $actorEmail,
                );
                $branding = $this->brandingResolver->finalizeHostSdkBranding(
                    $branding,
                    $zoomHost,
                    $actorUser instanceof User ? $actorUser : null,
                );
                $dailySdk['user_name'] = $this->hostOrgDisplayName($branding);
            }

            return response()->json(array_merge([
                'provider' => 'daily',
                'sdk' => $dailySdk,
            ], $branding));
        }

        $joinPasswords = $this->resolveSdkJoinPasswords($meetingNumber, $data['password'] ?? null);

        $userEmail = trim((string) ($data['user_email'] ?? ''));
        $userEmail = $userEmail !== '' ? $userEmail : null;

        $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
            $platformInstitutionId,
            $actorUser?->id ? (int) $actorUser->id : null,
            $actorEmail,
        );
        $branding = $this->meetingBrandingPayload(
            $actorEmail,
            $platformInstitutionId,
            $role === 1
                ? $this->hostBrandingAllowsActorInstitutionFallback($actorUser, $data)
                : true,
        );
        $branding = $this->brandingResolver->finalizeHostSdkBranding(
            $branding,
            $zoomHost,
            $actorUser,
        );

        if ($role === 1) {
            $userName = $this->hostOrgDisplayName($branding);
        }

        try {
            $payload = $this->sdkService->buildJoinPayload(
                $meetingNumber,
                $userName,
                $role,
                $joinPasswords['password'],
                $this->hostZakForRole($role),
                $userEmail,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload['password_candidates'] = $joinPasswords['candidates'];

        if ($role === 1) {
            $response = ['provider' => 'zoom', 'sdk' => $payload];
            $response = array_merge($response, $branding);
            return response()->json($response);
        }

        return response()->json(array_merge(['provider' => 'zoom', 'sdk' => $payload], $branding));
    }

    public function learnerMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'student_id' => 'nullable|integer|exists:students,id',
            'learner_email' => 'nullable|email',
        ]);

        return $this->materialAuth($material, 0, $data);
    }

    public function instructorMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        return $this->materialAuth($material, 1, $data);
    }

    public function instructorPreviewMaterialAuth(Request $request, CourseMaterial $material): JsonResponse
    {
        $data = $request->validate([
            'instructor_email' => 'required|email',
        ]);

        return $this->materialAuth($material, 0, array_merge($data, ['preview' => true]));
    }

    public function webinarHostAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_name' => 'nullable|string|max:120',
            'user_email' => 'nullable|email',
            'platform_institution_id' => 'nullable|integer',
            'refresh_host_profile' => 'nullable|boolean',
        ]);

        if ($request->boolean('refresh_host_profile')) {
            $this->zoomService->invalidateHostUserCache();
        }

        return $this->buildWebinarHostAuth($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function materialAuth(CourseMaterial $material, int $role, array $data): JsonResponse
    {
        $material->loadMissing('course');

        if (!CourseMaterialHelper::isLiveClassSession($material)) {
            return response()->json(['message' => 'This material is not a live class.'], 422);
        }

        if (!$material->course) {
            return response()->json(['message' => 'This live class has no course assigned.'], 422);
        }

        $isDaily = CourseMaterialHelper::isDailyMeeting($material);

        if (!$isDaily) {
            $meetingId = CourseMaterialHelper::meetingId($material);
            if (!$meetingId) {
                return response()->json(['message' => 'No Zoom meeting ID for this session.'], 422);
            }
        }

        /** @var User|null $instructor */
        $instructor = null;

        if ($role === 1) {
            $email = trim((string) ($data['instructor_email'] ?? ''));
            if ($email === '') {
                return response()->json(['message' => 'Instructor email is required to host.'], 422);
            }

            $instructor = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower($email)])
                ->first();
            if (!$instructor || !$this->canHostLiveClass($instructor, $material)) {
                return response()->json(['message' => 'You are not authorized to host this session.'], 403);
            }

            // Placeholder only — replaced with org/hub name before join.
            $userName = trim((string) ($instructor->name ?? '')) ?: 'Instructor';
            $participantAvatar = !empty($instructor->avatar) ? (string) $instructor->avatar : null;
            $joinUserEmail = $email !== '' ? $email : null;
        } else {
            $preview = !empty($data['preview']);
            $email = trim((string) ($data['instructor_email'] ?? ''));
            $participantAvatar = null;

            if ($preview && $email !== '') {
                $instructor = User::query()->where('email', $email)->first();
                if (!$instructor || !$this->canHostLiveClass($instructor, $material)) {
                    return response()->json(['message' => 'You are not authorized to preview this session.'], 403);
                }

                $userName = trim((string) ($instructor->name ?? '')) ?: 'Instructor preview';
                $participantAvatar = !empty($instructor->avatar) ? (string) $instructor->avatar : null;
            } else {
                $studentId = (int) ($data['student_id'] ?? 0);
                if ($studentId <= 0 && !empty($data['learner_email'])) {
                    $byEmail = Student::query()
                        ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim((string) $data['learner_email']))])
                        ->first();
                    if ($byEmail) {
                        $studentId = (int) $byEmail->id;
                    }
                }

                if ($studentId <= 0) {
                    return response()->json(['message' => 'Student ID is required to join. Sign in as a learner first.'], 422);
                }

                $enrolled = CourseEnrollment::query()
                    ->where('course_id', $material->course_id)
                    ->where('student_id', $studentId)
                    ->whereIn('status', EnrollmentStatusHelper::accessStatuses())
                    ->exists();

                if (!$enrolled) {
                    return response()->json([
                        'message' => 'You are not enrolled in this course, or your enrollment is not yet approved.',
                    ], 403);
                }

                $state = CourseMaterialHelper::liveSessionState($material);
                if (empty($state['can_join'])) {
                    return response()->json(['message' => 'This class is not live yet. Wait for the instructor to start.'], 403);
                }

                $student = Student::query()->find($studentId);
                if (!$student) {
                    return response()->json(['message' => 'Student not found.'], 404);
                }

                $userName = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
                if ($userName === '') {
                    $userName = (string) ($student->email ?? 'Learner');
                }
                $participantAvatar = !empty($student->avatar) ? (string) $student->avatar : null;
                $joinUserEmail = trim((string) ($student->email ?? '')) ?: null;

                $this->lobbyService->recordCheckIn(
                    (int) $material->id,
                    $studentId,
                    $userName,
                    (string) ($student->email ?? '')
                );
            }
        }

        if ($isDaily) {
            $actorEmail = trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? $joinUserEmail ?? ''));
            $actorEmail = $actorEmail !== '' ? $actorEmail : null;
            $actorUser = $instructor ?: ($actorEmail
                ? User::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($actorEmail))])->first()
                : null);
            $courseInstitutionId = !empty($material->course?->platform_institution_id)
                ? (int) $material->course->platform_institution_id
                : null;
            $platformInstitutionId = $this->resolveHostTenantInstitutionId($actorUser, $data, $courseInstitutionId);

            $branding = [];
            if ($role === 1) {
                try {
                    $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
                        $platformInstitutionId,
                        $actorUser?->id ? (int) $actorUser->id : null,
                        $actorEmail,
                    );
                    $branding = $this->meetingBrandingPayload(
                        $actorEmail,
                        $platformInstitutionId,
                        $this->hostBrandingAllowsActorInstitutionFallback($actorUser, $data),
                    );
                    $branding = $this->brandingResolver->finalizeHostSdkBranding(
                        $branding,
                        $zoomHost,
                        $actorUser,
                    );
                    $userName = $this->hostOrgDisplayName($branding);
                    if (!empty($branding['host']['avatar_url'])) {
                        $participantAvatar = $branding['host']['avatar_url'];
                    }
                } catch (\Throwable $e) {
                    Log::warning('Daily host branding failed; continuing with basic host name', [
                        'material_id' => $material->id,
                        'error' => $e->getMessage(),
                    ]);
                    $userName = trim((string) ($instructor->name ?? $userName ?? 'Instructor')) ?: 'Instructor';
                }
            }

            try {
                $participantId = $role === 1
                    ? ('instructor-' . ($joinUserEmail ?? 'host'))
                    : ('student-' . (string) ($data['student_id'] ?? $data['learner_email'] ?? 'guest'));
                $dailySdk = $this->liveMeetingJoin->buildDailySdkPayload(
                    $material,
                    $userName,
                    (string) $participantId,
                    $role === 1 && empty($data['preview']),
                );
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(array_merge([
                'provider' => 'daily',
                'sdk' => $dailySdk,
                'material' => [
                    'id' => $material->id,
                    'title' => $material->title,
                    'course_title' => $material->course?->title,
                    'recording_enabled' => (bool) (
                        data_get($material->metadata, 'recording_enabled')
                        ?? data_get($material->metadata, 'auto_recording', false)
                    ),
                ],
                'preview' => !empty($data['preview']),
                'participant' => [
                    'name' => $userName,
                    'avatar_url' => $participantAvatar ?? null,
                ],
            ], $branding));
        }

        $meetingId = CourseMaterialHelper::meetingId($material);

        $meetingDetails = null;
        $fetched = $this->zoomService->getMeeting($meetingId);
        if (is_array($fetched) && empty($fetched['error'])) {
            $meetingDetails = $fetched;
        }

        $passwordCandidates = $this->zoomService->resolveMaterialJoinPasswordCandidates($material, $meetingDetails);
        $password = $passwordCandidates[0] ?? (CourseMaterialHelper::meetingPassword($material) ?? '');

        $actorEmail = trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? ''));
        $actorEmail = $actorEmail !== '' ? $actorEmail : null;
        $actorUser = $instructor ?: ($actorEmail
            ? User::query()->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($actorEmail))])->first()
            : null);
        $courseInstitutionId = !empty($material->course?->platform_institution_id)
            ? (int) $material->course->platform_institution_id
            : null;
        $platformInstitutionId = $this->resolveHostTenantInstitutionId($actorUser, $data, $courseInstitutionId);

        $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
            $platformInstitutionId,
            $actorUser?->id ? (int) $actorUser->id : null,
            $actorEmail,
        );
        $branding = $this->meetingBrandingPayload(
            $actorEmail,
            $platformInstitutionId,
            $role === 1
                ? $this->hostBrandingAllowsActorInstitutionFallback($actorUser, $data)
                : true,
        );
        $branding = $this->brandingResolver->finalizeHostSdkBranding(
            $branding,
            $zoomHost,
            $actorUser,
        );

        if ($role === 1) {
            // Always org/hub name in Zoom tile — never Zoom profile or personal user name.
            $userName = $this->hostOrgDisplayName($branding);
        }

        try {
            $payload = $this->sdkService->buildJoinPayload(
                $meetingId,
                $userName,
                $role,
                $password,
                $this->hostZakForRole($role),
                $joinUserEmail,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload['password_candidates'] = $passwordCandidates;

        $sessionTitle = trim((string) ($material->title ?? ''));
        if ($sessionTitle !== '') {
            $branding['session_title'] = $sessionTitle;
        }

        return response()->json(array_merge([
            'provider' => 'zoom',
            'sdk' => $payload,
            'material' => [
                'id' => $material->id,
                'title' => $material->title,
                'course_title' => $material->course?->title,
                'recording_enabled' => (bool) (
                    data_get($material->metadata, 'recording_enabled')
                    ?? data_get($material->metadata, 'auto_recording', false)
                ),
            ],
            'preview' => !empty($data['preview']),
            'participant' => [
                'name' => $userName,
                'avatar_url' => $participantAvatar ?? null,
            ],
        ], $branding));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildWebinarHostAuth(array $data): JsonResponse
    {
        $request = request();
        $actorUser = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $actorEmail = $actorUser?->email
            ? trim((string) $actorUser->email)
            : (trim((string) ($data['user_email'] ?? $data['instructor_email'] ?? '')) ?: null);

        if (!$actorUser && $actorEmail) {
            $actorUser = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($actorEmail))])->first();
        }

        $appRole = strtolower(trim((string) ($actorUser->role ?? '')));
        $trustedHost = $actorUser && in_array($appRole, ['admin', 'staff', 'instructor', 'partner_company'], true);
        if (!$trustedHost) {
            return response()->json([
                'message' => 'Only an authenticated host can start this webinar session.',
            ], 403);
        }

        $actorInstitutionId = $actorUser instanceof User && !empty($actorUser->platform_institution_id)
            && !PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)
            ? (int) $actorUser->platform_institution_id
            : null;

        $settings = WebinarSetting::forInstitution($actorInstitutionId);

        // Keep the authenticated host for branding. Do not swap the actor to a stored
        // zoom_host_user_id email — that can pull a partner institution onto the hub.
        $settingsInstitutionId = $settings->platform_institution_id ? (int) $settings->platform_institution_id : null;
        $platformInstitutionId = $this->resolveHostTenantInstitutionId(
            $actorUser instanceof User ? $actorUser : null,
            $data,
            $settingsInstitutionId ?? $actorInstitutionId,
        );
        // Main platform hosts always brand as the hub, never a partner settings tenant.
        if ($actorUser instanceof User && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
            $platformInstitutionId = null;
        }
        $zoomHost = $this->zoomService->resolveConfiguredHostBranding(
            $platformInstitutionId,
            $actorUser?->id ? (int) $actorUser->id : null,
            $actorEmail,
        );
        $branding = $this->meetingBrandingPayload(
            $actorEmail,
            $platformInstitutionId,
            $this->hostBrandingAllowsActorInstitutionFallback($actorUser, $data),
        );
        $branding = $this->brandingResolver->finalizeHostSdkBranding(
            $branding,
            $zoomHost,
            $actorUser,
        );

        $userName = $this->hostOrgDisplayName($branding);

        // Prefer Daily when platform provider is Daily (or an existing Daily webinar room).
        if ($this->webinarDaily->shouldUseDaily() || $this->webinarDaily->isDailyWebinar($settings)) {
            if (trim((string) ($settings->zoom_meeting_id ?? '')) === '' || !$this->webinarDaily->isDailyWebinar($settings)) {
                $ensured = $this->webinarDaily->ensureRoom($settings, $platformInstitutionId);
                if (!$ensured['ok']) {
                    return response()->json(['message' => $ensured['message'] ?? 'Could not prepare Daily webinar room.'], 422);
                }
                $settings = $ensured['settings'];
            }

            try {
                $dailySdk = $this->webinarDaily->buildSdkPayload(
                    $settings,
                    $userName,
                    $actorEmail ?: ('host-' . ($actorUser?->id ?? 'webinar')),
                    true,
                );
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(array_merge([
                'provider' => 'daily',
                'sdk' => $dailySdk,
            ], $branding));
        }

        $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($meetingId === '') {
            return response()->json(['message' => 'No webinar meeting configured. Prepare the webinar first.'], 422);
        }

        $meetingDetails = $this->fetchMeetingDetailsForSdk($meetingId);
        $joinPasswords = $this->resolveSdkJoinPasswords($meetingId, null, $settings, $meetingDetails);
        $this->persistWebinarPasswordIfResolved($settings, $joinPasswords);

        try {
            // Same-account embedded host: role=1 JWT (no ZAK), matching Live Zoom Cohort.
            $payload = $this->sdkService->buildJoinPayload(
                $meetingId,
                $userName,
                1,
                $joinPasswords['password'],
                null,
                $zoomHost['email'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payload['password_candidates'] = $joinPasswords['candidates'];

        return response()->json(array_merge(
            ['provider' => 'zoom', 'sdk' => $payload],
            $branding,
        ));
    }

    /**
     * Zoom host profile picture + display name from ZOOM_HOST_USER_ID (.env).
     *
     * @return array{host: array{name: string, email: string|null, avatar_url: string|null}, company: array{name: string}}
     */
    protected function meetingBrandingPayload(
        ?string $actorEmail = null,
        ?int $platformInstitutionId = null,
        bool $allowActorInstitutionFallback = true,
    ): array {
        if ($actorEmail) {
            $actorUser = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($actorEmail))])
                ->first();
            if ($actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
                $platformInstitutionId = null;
                $allowActorInstitutionFallback = false;
            }
        }

        if ($platformInstitutionId === null && !$allowActorInstitutionFallback) {
            $allowActorInstitutionFallback = false;
        }

        return $this->brandingResolver->resolve(
            $actorEmail,
            $platformInstitutionId,
            null,
            $allowActorInstitutionFallback,
        );
    }

    /** Institution display name in Zoom SDK (never the instructor's personal name). */
    protected function institutionHostJoinName(array $branding): string
    {
        $name = trim((string) ($branding['institution']['name'] ?? $branding['company']['name'] ?? ''));

        return $name !== '' ? $name : 'Host';
    }

    /**
     * Host tile name = logged-in org (partner institution or Xander Learning Hub).
     * Never Zoom profile name or personal account name.
     *
     * @param  array<string, mixed>  $branding
     */
    protected function hostOrgDisplayName(array $branding): string
    {
        if ($branding['use_institution_logo'] ?? false) {
            return $this->institutionHostJoinName($branding);
        }

        $hub = trim((string) ($branding['host']['name'] ?? $branding['company']['name'] ?? ''));
        if ($hub !== '') {
            return $hub;
        }

        return $this->brandingResolver->platformCompanyNameForResponse();
    }

    /**
     * Host branding tenant follows the logged-in host — never the course owner's institution
     * when a main-platform admin hosts an institution-owned live class.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveHostTenantInstitutionId(?User $actorUser, array $data, ?int $courseInstitutionId): ?int
    {
        if ($actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
            return null;
        }

        $hasRequestTenant = array_key_exists('platform_institution_id', $data)
            && $data['platform_institution_id'] !== null
            && $data['platform_institution_id'] !== '';

        // Never trust a client-supplied tenant unless it matches the authenticated actor.
        if ($hasRequestTenant && (int) $data['platform_institution_id'] > 0) {
            $requested = (int) $data['platform_institution_id'];
            if ($actorUser && (int) ($actorUser->platform_institution_id ?? 0) === $requested) {
                return $requested;
            }
            // Ignore mismatched spoofed tenant ids.
        }

        $role = strtolower(trim((string) ($actorUser->role ?? '')));
        // Hub session: admin/staff with no institution must not inherit a stale tenant.
        if (in_array($role, ['admin', 'staff'], true)
            && empty($actorUser->platform_institution_id)
        ) {
            return null;
        }

        if ($actorUser && !empty($actorUser->platform_institution_id)) {
            return (int) $actorUser->platform_institution_id;
        }

        // Institution instructors / partners: fall back to course/meeting tenant when needed.
        return $courseInstitutionId && $courseInstitutionId > 0 ? $courseInstitutionId : null;
    }

    protected function resolveTrustedOwner(?User $sanctumUser, ?User $actorUser): bool
    {
        $userForTrust = $sanctumUser ?? $actorUser;
        if (!$userForTrust) {
            return false;
        }

        $appRole = strtolower((string) ($userForTrust->role ?? ''));

        return in_array($appRole, ['admin', 'staff', 'instructor', 'partner_company'], true);
    }

    protected function isHubScopedDailyRoom(string $roomName): bool
    {
        $roomName = trim($roomName);

        return $roomName !== ''
            && (str_contains($roomName, '-main-')
                || str_starts_with($roomName, 'admin-meet-main-')
                || str_starts_with($roomName, 'admin-webinar-main-'));
    }

    protected function adminMeetingBrandingInstitutionId(
        \App\Models\AdminZoomMeeting $meeting,
        ?User $actorUser,
        int $role,
    ): ?int {
        $room = trim((string) ($meeting->daily_room_name ?: $meeting->zoom_meeting_id ?? ''));
        if ($this->isHubScopedDailyRoom($room)) {
            return null;
        }

        $meetingInstitutionId = $meeting->platform_institution_id
            ? (int) $meeting->platform_institution_id
            : null;

        if ($role === 1 && $actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser) && $meetingInstitutionId === null) {
            return null;
        }

        return $meetingInstitutionId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function hostBrandingAllowsActorInstitutionFallback(?User $actorUser, array $data): bool
    {
        if ($actorUser && PlatformInstitutionHelper::isMainPlatformAdmin($actorUser)) {
            return false;
        }

        $hasRequestTenant = array_key_exists('platform_institution_id', $data)
            && $data['platform_institution_id'] !== null
            && $data['platform_institution_id'] !== ''
            && (int) $data['platform_institution_id'] > 0;

        if ($hasRequestTenant) {
            return true;
        }

        $role = strtolower(trim((string) ($actorUser->role ?? '')));
        if (in_array($role, ['admin', 'staff'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Embedded Meeting SDK (same Zoom account): host starts with role=1 JWT signature only.
     * Passing ZAK together with the signature triggers Zoom SDK "Token error".
     */
    protected function hostZakForRole(int $role): ?string
    {
        return null;
    }

    protected function canHostLiveClass(User $user, CourseMaterial $material): bool
    {
        $course = \App\Models\Course::query()->find($material->course_id);
        if (!$course || !PlatformTenantScope::userOwnsCourse($user, $course)) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));
        if (in_array($role, ['admin', 'staff', 'partner_company'], true)) {
            return true;
        }

        if ($role !== 'instructor') {
            return false;
        }

        return $user->assignedCourses()->where('courses.id', $material->course_id)->exists();
    }

    /**
     * @return array{password: string, candidates: list<string>}
     */
    protected function resolveSdkJoinPasswords(
        string $meetingId,
        ?string $requestPassword = null,
        ?WebinarSetting $webinarSettings = null,
        ?array $meetingDetails = null,
    ): array {
        $meetingDetails = $meetingDetails ?? $this->fetchMeetingDetailsForSdk($meetingId);

        $settings = $webinarSettings;
        if (!$settings) {
            $settings = WebinarSetting::query()
                ->where('zoom_meeting_id', $meetingId)
                ->first()
                ?? WebinarSetting::current();
        }
        if ((string) ($settings->zoom_meeting_id ?? '') === $meetingId) {
            $candidates = $this->zoomService->resolveWebinarJoinPasswordCandidates($settings, $meetingDetails);
        } else {
            $candidates = [];
            $requestPassword = trim((string) ($requestPassword ?? ''));
            if ($requestPassword !== '') {
                $candidates[] = $requestPassword;
            }
            if (is_array($meetingDetails) && empty($meetingDetails['error'])) {
                foreach (['password', 'passcode', 'h323_password', 'encrypted_password'] as $key) {
                    $value = $meetingDetails[$key] ?? null;
                    if (is_string($value) && trim($value) !== '') {
                        $candidates[] = trim($value);
                    }
                }
            }
            $candidates[] = '';
            $candidates = array_values(array_unique($candidates, SORT_STRING));
        }

        return [
            'password' => $candidates[0] ?? '',
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchMeetingDetailsForSdk(string $meetingId): ?array
    {
        if (!$this->zoomService->canManageMeetingViaApi($meetingId)) {
            return null;
        }

        $fetched = $this->zoomService->getMeeting($meetingId);

        return is_array($fetched) && empty($fetched['error']) ? $fetched : null;
    }

    protected function persistWebinarPasswordIfResolved(WebinarSetting $settings, array $joinPasswords): void
    {
        if (!Schema::hasColumn('webinar_settings', 'zoom_password')) {
            return;
        }

        if (trim((string) ($settings->zoom_password ?? '')) !== '') {
            return;
        }

        foreach ($joinPasswords['candidates'] as $candidate) {
            if ($candidate !== '') {
                $settings->zoom_password = $candidate;
                $settings->save();
                break;
            }
        }
    }

    protected function resolveZoomHostJoinName(
        ?string $fallback = null,
        ?int $platformInstitutionId = null,
        ?int $platformUserId = null,
        ?string $actorEmail = null,
    ): string {
        $zoomName = trim((string) ($this->zoomService->resolveConfiguredHostBranding(
            $platformInstitutionId,
            $platformUserId,
            $actorEmail,
        )['name'] ?? ''));
        if ($zoomName !== '') {
            return $zoomName;
        }

        $fallback = trim((string) ($fallback ?? ''));
        if ($fallback !== '' && strcasecmp($fallback, 'Host') !== 0) {
            return $fallback;
        }

        return 'Host';
    }
}
