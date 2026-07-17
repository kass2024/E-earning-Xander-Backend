<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\MeetingProvider;
use App\Services\MailDeliveryService;
use App\Services\Meetings\DailyApiService;
use App\Services\Meetings\DailyPermissionPolicy;
use App\Services\Meetings\AdminZoomMeetingJoinService;
use App\Services\PlatformSettingsService;
use App\Services\ZoomHostAssignmentService;
use App\Services\Meetings\MeetingProviderStatusService;
use App\Services\ZoomService;
use App\Models\User;
use App\Support\AdminRecordingCatalog;
use App\Support\AdminZoomMeetingRegistry;
use App\Support\FrontendUrl;
use App\Support\MeetingJoinUrl;
use App\Support\PlatformInstitutionHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ZoomController extends Controller
{
    protected ZoomService $zoom;

    protected MailDeliveryService $mail;

    public function __construct(ZoomService $zoom, MailDeliveryService $mail)
    {
        $this->zoom = $zoom;
        $this->mail = $mail;
    }

    public function listMeetings(Request $request)
    {
        $tenant = $this->resolveManagementTenant($request);
        if ($tenant['actor'] === null) {
            return response()->json([
                'meetings' => [],
                'message' => 'Sign in required to list meetings.',
            ], 401);
        }

        // Shared Zoom host pool — host lookup may use institution for assignment, but list filter is actor-scoped.
        $hostUser = $this->zoom->resolveHostUserId($tenant['institutionId']);
        $data = $this->zoom->listMeetings($hostUser);

        if ($data === null) {
            return response()->json([
                'meetings' => [],
                'fallback_only' => true,
                'message' => 'Zoom API is not configured or unreachable.',
            ], 200);
        }

        $zoomMeetings = [];
        if (is_array($data) && isset($data['meetings']) && is_array($data['meetings'])) {
            $zoomMeetings = $data['meetings'];
        }

        $meetings = AdminZoomMeetingRegistry::meetingsForManagementPage(
            $zoomMeetings,
            $tenant['institutionId'],
            $tenant['isMainAdmin'],
        );
        $meetings = $this->zoom->annotateMeetingSessionStatuses($meetings, $hostUser);

        if ($request->boolean('include_recordings')) {
            $endedMeetings = array_values(array_filter(
                $meetings,
                fn ($meeting) => is_array($meeting) && ($meeting['session_status'] ?? '') === 'ended'
            ));
            $annotatedEnded = $this->zoom->annotateMeetingRecordings($endedMeetings);
            $byId = [];
            foreach ($annotatedEnded as $meeting) {
                if (!is_array($meeting)) {
                    continue;
                }
                $id = trim((string) ($meeting['id'] ?? ''));
                if ($id !== '') {
                    $byId[$id] = $meeting;
                }
            }
            $meetings = array_map(function ($meeting) use ($byId) {
                if (!is_array($meeting)) {
                    return $meeting;
                }
                $id = trim((string) ($meeting['id'] ?? ''));

                return ($id !== '' && isset($byId[$id])) ? $byId[$id] : $meeting;
            }, $meetings);
        }

        return response()->json(array_merge(is_array($data) ? $data : [], ['meetings' => $meetings]), 200);
    }

    public function listHosts(Request $request, ZoomHostAssignmentService $assignment)
    {
        $institutionId = $request->filled('platform_institution_id')
            ? (int) $request->input('platform_institution_id')
            : null;

        return response()->json($assignment->getHostInventory($institutionId));
    }

    public function meetingProviderStatus(MeetingProviderStatusService $status)
    {
        return response()->json($status->summary());
    }

    public function listRecordings(Request $request)
    {
        if (!$this->zoom->isConfigured()) {
            return response()->json([
                'message' => 'Zoom API is not configured. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET.',
                'recordings' => [],
                'zoom_api_configured' => false,
            ], 200);
        }

        $tenant = $this->resolveManagementTenant($request);
        if ($tenant['actor'] === null) {
            return response()->json([
                'recordings' => [],
                'message' => 'Sign in required to list recordings.',
            ], 401);
        }

        $refresh = $request->boolean('refresh');
        $trackedIds = AdminRecordingCatalog::trackedMeetingIdsForTenant(
            $tenant['institutionId'],
            $tenant['isMainAdmin'],
        );
        $monthsBack = 3;
        $collected = $this->zoom->cachedCloudRecordings($trackedIds, $monthsBack, $refresh);

        $items = AdminRecordingCatalog::filterPlatformOnly(
            AdminRecordingCatalog::annotateItems(
                $this->zoom->formatRecordingItems(['meetings' => $collected['meetings']])
            )
        );

        $scopeHint = null;
        if ($items === [] && $collected['errors'] !== []) {
            $scopeHint = 'Add Zoom scopes cloud_recording:read:list_user_recordings:admin and cloud_recording:read:list_recording_files:admin to your Server-to-Server app, then re-activate it.';
        }

        return response()->json([
            'recordings' => $items,
            'zoom_api_configured' => true,
            'total' => count($items),
            'tracked_meeting_ids' => count($trackedIds),
            'load_strategies' => $collected['strategies'],
            'zoom_errors' => $collected['errors'],
            'scope_hint' => $scopeHint,
            'cached' => (bool) ($collected['cached'] ?? false),
        ], 200);
    }

    public function streamRecording(Request $request)
    {
        $corsHeaders = $this->recordingStreamCorsHeaders($request);

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $corsHeaders);
        }

        $request->validate([
            'url' => 'required|url|max:4000',
        ]);

        $url = (string) $request->query('url');
        $range = $request->header('Range');
        $headOnly = $request->isMethod('HEAD');

        $probe = $this->zoom->probeRecordingStream($url, $range, $headOnly);

        if ($probe === null || empty($probe['ok'])) {
            return response()->json([
                'message' => $probe['message'] ?? 'Unable to stream this recording',
            ], $probe['status'] ?? 502);
        }

        $forwardHeaders = array_merge($corsHeaders, $probe['headers']);

        if ($headOnly) {
            return response('', $probe['status'], $forwardHeaders);
        }

        /** @var \Illuminate\Http\Client\Response $response */
        $response = $probe['response'];

        return response()->stream(function () use ($response) {
            $body = $response->toPsrResponse()->getBody();
            while (!$body->eof()) {
                echo $body->read(1024 * 128);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $probe['status'], $forwardHeaders);
    }

    /**
     * @return array<string, string>
     */
    private function recordingStreamCorsHeaders(Request $request): array
    {
        $allowed = array_values(array_unique(array_filter([
            rtrim((string) config('app.frontend_url'), '/'),
            FrontendUrl::base(),
            'https://parrotglobalstudyacademy.ca',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ])));

        $origin = (string) $request->headers->get('Origin', '');
        $allowOrigin = in_array($origin, $allowed, true)
            ? $origin
            : ($allowed[0] ?? '*');

        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Range, Accept, Content-Type, Origin',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges, Content-Type',
            'Vary' => 'Origin',
        ];
    }

    public function createMeeting(Request $request)
    {
        $request->validate([
            'topic'      => 'required|string|max:255',
            'start_time' => 'nullable|string',
            'duration'   => 'nullable|integer',
            'timezone'   => 'nullable|string',
            'agenda'     => 'nullable|string',
            'invite_emails' => 'nullable|string',
            'join_before_host' => 'nullable|boolean',
            'mute_upon_entry' => 'nullable|boolean',
            'auto_recording' => 'nullable|boolean',
            'host_video' => 'nullable|boolean',
            'participant_video' => 'nullable|boolean',
            'waiting_room' => 'nullable|boolean',
            'meeting_authentication' => 'nullable|boolean',
            'registrants_email_notification' => 'nullable|boolean',
            'allow_multiple_devices' => 'nullable|boolean',
            'require_registration' => 'nullable|boolean',
            'audio' => 'nullable|string|in:both,voip,telephony',
            'type' => 'nullable|string|in:meeting,webinar',
            'recurrence' => 'nullable|string|in:none,daily,weekly,monthly',
            'reminder' => 'nullable|string|in:none,10m,1h,24h',
            'category' => 'nullable|string|max:100',
        ]);

        $payload = $request->all();

        $tenant = $this->resolveManagementTenant($request);
        $user = $tenant['actor'];
        if ($user === null) {
            return response()->json(['message' => 'Sign in required to create meetings.'], 401);
        }
        // Stamp from authenticated actor — never trust client-only platform_institution_id.
        $institutionId = $tenant['institutionId'];
        $hostId = $this->zoom->resolveHostUserId(
            $institutionId,
            $user->id ? (int) $user->id : null,
            $user->email,
        );

        $isWebinar = strtolower((string) ($payload['type'] ?? 'meeting')) === 'webinar';
        $meetingMode = $isWebinar
            ? DailyPermissionPolicy::MODE_WEBINAR
            : DailyPermissionPolicy::MODE_MEETING;

        /** Prefer Daily when the main platform provider is Daily and Daily is configured. */
        $platformProvider = app(PlatformSettingsService::class)->mainPlatformMeetingProvider();
        $daily = app(DailyApiService::class);
        if ($platformProvider === MeetingProvider::Daily && $daily->isConfigured()) {
            try {
                $daily->ensureDomainDefaults();
                $roomName = ($isWebinar ? 'admin-webinar-' : 'admin-meet-')
                    . ($institutionId && $institutionId > 0 ? $institutionId : 'main')
                    . '-' . Str::lower(Str::random(8));
                $startAt = !empty($payload['start_time'])
                    ? \Carbon\Carbon::parse((string) $payload['start_time'])
                    : now();
                $duration = (int) ($payload['duration'] ?? 30);
                $grace = (int) config('daily.room_grace_minutes', 30);
                $room = $daily->createRoom($roomName, $daily->classroomRoomProperties([
                    'exp' => $startAt->copy()->addMinutes($duration + $grace)->timestamp,
                    'start_audio_off' => (bool) ($payload['mute_upon_entry'] ?? true),
                    'start_video_off' => true,
                ]));
                $resolvedName = (string) ($room['name'] ?? $roomName);
                $roomUrl = (string) ($room['url'] ?? $daily->roomUrl($resolvedName));
                $appJoinUrl = MeetingJoinUrl::participantUrl($resolvedName);
                $appHostUrl = MeetingJoinUrl::hostUrl($resolvedName);

                $data = [
                    'id' => $resolvedName,
                    'uuid' => $resolvedName,
                    'topic' => (string) ($payload['topic'] ?? 'Meeting'),
                    'start_time' => $startAt->toIso8601String(),
                    'duration' => $duration,
                    'join_url' => $appJoinUrl,
                    'start_url' => $appHostUrl,
                    'provider_join_url' => $roomUrl,
                    'password' => null,
                    'agenda' => $payload['agenda'] ?? null,
                    'provider' => 'daily',
                    'meeting_mode' => $meetingMode,
                    'session_status' => 'upcoming',
                ];

                AdminZoomMeetingRegistry::register($data, $user->id ? (int) $user->id : null, array_merge($payload, [
                    'type' => $meetingMode,
                    'meeting_provider' => 'daily',
                    'meeting_mode' => $meetingMode,
                    'daily_room_name' => $resolvedName,
                    'daily_room_url' => $roomUrl,
                    'platform_institution_id' => $institutionId,
                ]));

                if (!empty($payload['invite_emails'])) {
                    $this->sendInviteEmails($payload, $data, $user);
                }

                return response()->json([
                    'provider' => 'daily',
                    'meeting_mode' => $meetingMode,
                    'zoom' => $data,
                    'host_name' => $user->name ?? null,
                    'host_email' => $user->email ?? null,
                    'start_url' => $appHostUrl,
                    'join_url' => $appJoinUrl,
                ], 201);
            } catch (\Throwable $e) {
                Log::error('Daily admin meeting create failed', ['error' => $e->getMessage()]);

                return response()->json([
                    'message' => 'Unable to create Daily meeting: ' . $e->getMessage(),
                ], 500);
            }
        }

        $data = $isWebinar
            ? $this->zoom->createWebinar($payload, $hostId)
            : $this->zoom->createMeeting($payload, $hostId);
        if ($data === null) {
            return response()->json(['message' => 'Unable to create meeting on Zoom (no response)'], 500);
        }

        if (isset($data['error']) && !empty($data['error'])) {
            $body = $data['body'] ?? [];
            $message = $body['message'] ?? 'Zoom returned an error while creating the meeting.';

            return response()->json([
                'message' => $message,
                'zoom' => $body,
            ], 422);
        }

        // Optionally send invite emails to staff/users
        if (!empty($payload['invite_emails'])) {
            $this->sendInviteEmails($payload, $data, $user);
        }

        AdminZoomMeetingRegistry::register($data, $user->id ? (int) $user->id : null, array_merge($payload, [
            'meeting_provider' => 'zoom',
            'meeting_mode' => $meetingMode,
            'platform_institution_id' => $institutionId,
        ]));

        $meetingId = trim((string) ($data['id'] ?? ''));
        $appJoinUrl = $meetingId !== ''
            ? MeetingJoinUrl::participantUrl($meetingId)
            : ($data['join_url'] ?? null);
        $appHostUrl = $meetingId !== ''
            ? MeetingJoinUrl::hostUrl($meetingId)
            : ($data['start_url'] ?? null);

        // Include host details and explicit links in the response
        $responseBody = [
            'provider' => 'zoom',
            'meeting_mode' => $meetingMode,
            'zoom' => array_merge(is_array($data) ? $data : [], [
                'join_url' => $appJoinUrl,
                'start_url' => $appHostUrl,
            ]),
            'host_name' => $user->name ?? null,
            'host_email' => $user->email ?? null,
            'start_url' => $appHostUrl,
            'join_url' => $appJoinUrl,
        ];

        return response()->json($responseBody, 201);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    protected function sendInviteEmails(array $payload, array $data, $user): void
    {
        $rawList = $payload['invite_emails'] ?? '';
        $emails = array_filter(array_map('trim', explode(',', (string) $rawList)));
        if (empty($emails)) {
            return;
        }

        $topic = (string) ($data['topic'] ?? $payload['topic'] ?? 'Meeting');
        $isWebinar = strtolower((string) ($payload['type'] ?? $data['meeting_mode'] ?? 'meeting')) === 'webinar';
        $subject = ($isWebinar ? 'Webinar invitation: ' : 'Meeting invitation: ') . $topic;
        $meetingId = trim((string) ($data['id'] ?? $data['uuid'] ?? ''));
        $startTime = $data['start_time'] ?? ($payload['start_time'] ?? null);
        $password = $data['password'] ?? ($payload['password'] ?? null);

        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Always send our app-domain join link (Daily rooms are private — raw daily.co fails with "not allowed").
            // Include email as both user_email and user_name so the guest tile shows who was invited.
            $joinUrl = $meetingId !== ''
                ? MeetingJoinUrl::participantUrl($meetingId, $email, $email)
                : MeetingJoinUrl::preferAppJoinUrl($data['join_url'] ?? null, $meetingId);

            $lines = [$subject];
            if ($startTime) {
                $lines[] = 'Time: ' . $startTime;
            }
            if ($joinUrl) {
                $lines[] = 'Join link: ' . $joinUrl;
            }
            if ($password) {
                $lines[] = 'Password: ' . $password;
            }
            $lines[] = '';
            $lines[] = 'Open the link above to join. You will enter according to the host settings for this session.';
            $lines[] = 'Sent from ' . config('app.name') . ' system.';
            $bodyText = implode("\n", $lines);

            $this->mail->sendRaw($bodyText, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            }, [
                'event' => 'zoom_invite',
                'email' => $email,
            ]);
        }
    }

    public function deleteMeeting(Request $request, string $id)
    {
        $id = trim($id);
        $joinService = app(AdminZoomMeetingJoinService::class);

        $adminMeeting = \App\Models\AdminZoomMeeting::query()
            ->where(function ($query) use ($id) {
                $query->where('zoom_meeting_id', $id)
                    ->orWhere('daily_room_name', $id);
            })
            ->first();
        if (!$adminMeeting && $joinService->looksLikeDailyRoomName($id)) {
            $adminMeeting = $joinService->findByRoomName($id);
        }

        $actor = \App\Support\PlatformInstitutionHelper::resolveActorFromRequest($request) ?: $request->user();
        if ($adminMeeting) {
            $meetingInstitutionId = $adminMeeting->platform_institution_id
                ? (int) $adminMeeting->platform_institution_id
                : null;
            $actorInstitutionId = $actor && !\App\Support\PlatformInstitutionHelper::isMainPlatformAdmin($actor)
                ? ((int) ($actor->platform_institution_id ?? 0) ?: null)
                : null;
            $isMain = \App\Support\PlatformInstitutionHelper::isMainPlatformAdmin($actor);
            if ($isMain && $meetingInstitutionId) {
                return response()->json(['message' => 'Hub operators can only delete hub meetings.'], 403);
            }
            if (!$isMain && $meetingInstitutionId !== $actorInstitutionId) {
                return response()->json(['message' => 'Not allowed to delete this meeting.'], 403);
            }

            if ($joinService->isDailyMeeting($adminMeeting) || $joinService->looksLikeDailyRoomName($id)) {
                $joinService->deleteDailyRoom($adminMeeting);
                AdminZoomMeetingRegistry::unregister((string) $adminMeeting->zoom_meeting_id);
                if ($id !== (string) $adminMeeting->zoom_meeting_id) {
                    AdminZoomMeetingRegistry::unregister($id);
                }

                return response()->json(['message' => 'Meeting deleted']);
            }
        }

        if ($joinService->looksLikeDailyRoomName($id)) {
            try {
                app(DailyApiService::class)->deleteRoom($id);
            } catch (\Throwable) {
                // Room may already be gone.
            }
            AdminZoomMeetingRegistry::unregister($id);

            return response()->json(['message' => 'Meeting removed']);
        }

        $digitsOnly = preg_replace('/\D+/', '', $id);
        if (!preg_match('/^\d{9,15}$/', (string) $digitsOnly)) {
            AdminZoomMeetingRegistry::unregister($id);

            return response()->json(['message' => 'Meeting removed']);
        }

        $ok = $this->zoom->deleteMeeting($id);
        AdminZoomMeetingRegistry::unregister($id);

        if (!$ok) {
            return response()->json([
                'message' => 'Meeting removed from dashboard. It may already be ended on Zoom.',
            ]);
        }

        return response()->json(['message' => 'Meeting deleted on Zoom']);
    }

    public function deleteRecording(Request $request, string $meetingId)
    {
        if (!$this->zoom->isConfigured()) {
            return response()->json(['message' => 'Zoom API is not configured'], 503);
        }

        $data = $request->validate([
            'recording_id' => 'nullable|string|max:255',
            'uuid' => 'nullable|string|max:500',
            'start_time' => 'nullable|string|max:100',
        ]);

        $targetId = !empty($data['uuid']) ? (string) $data['uuid'] : $meetingId;
        $result = $this->zoom->deleteCloudRecording($targetId, $data['recording_id'] ?? null);

        if (empty($result['ok'])) {
            Log::warning('Zoom cloud recording delete failed', [
                'meeting_id' => $meetingId,
                'target_id' => $targetId,
                'recording_id' => $data['recording_id'] ?? null,
                'result' => $result,
            ]);

            return response()->json([
                'message' => $result['message'] ?? 'Unable to delete recording from Zoom cloud',
                'details' => $result['body'] ?? null,
            ], $result['status'] ?? 502);
        }

        $this->zoom->purgeMeetingFromRecordingsCache(
            $meetingId,
            $data['uuid'] ?? null,
            $data['start_time'] ?? null,
            AdminRecordingCatalog::trackedMeetingIds(),
            3
        );

        return response()->json([
            'message' => $result['message'] ?? 'Recording deleted from Zoom cloud',
        ]);
    }

    public function setMeetingRecording(Request $request, string $id)
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        if ($id === 'pathways-webinar') {
            return response()->json([
                'message' => 'Use Webinar Signups to manage recording for registered meetings.',
            ], 422);
        }

        if ($this->zoom->isLegacyPathwaysPmiId($id)) {
            return response()->json([
                'message' => 'This personal meeting room cannot be managed via the Zoom API. Use Webinar Signups → Start Meeting to create an API session.',
            ], 422);
        }

        $enabled = (bool) $data['enabled'];
        $result = $this->zoom->setMeetingAutoRecording($id, $enabled);

        if ($result === null) {
            return response()->json(['message' => 'Unable to contact Zoom'], 503);
        }

        if (!empty($result['error'])) {
            return response()->json([
                'message' => 'Zoom rejected the recording setting change.',
                'details' => $result['body'] ?? null,
            ], 502);
        }

        return response()->json([
            'message' => $enabled ? 'Cloud recording enabled for this meeting.' : 'Cloud recording disabled.',
            'recording_enabled' => $enabled,
            'meeting_id' => $id,
        ]);
    }

    public function listWebinars()
    {
        $hostId = (string) config('services.zoom.host_user_id', 'me');
        $data = $this->zoom->listWebinars($hostId);
        if ($data === null) {
            return response()->json(['message' => 'Unable to contact Zoom'], 500);
        }

        return response()->json($data, 200);
    }

    public function createWebinar(Request $request)
    {
        $request->validate([
            'topic'      => 'required|string|max:255',
            'start_time' => 'nullable|string',
            'duration'   => 'nullable|integer',
            'timezone'   => 'nullable|string',
            'agenda'     => 'nullable|string',
        ]);

        $payload = $request->all();

        // Use logged-in user (instructor) as the Zoom host if available
        $user = $request->user();
        $hostId = $user && !empty($user->email)
            ? (string) $user->email
            : (string) config('services.zoom.host_user_id', 'me');

        $data = $this->zoom->createWebinar($payload, $hostId);
        if ($data === null) {
            return response()->json(['message' => 'Unable to create webinar on Zoom'], 500);
        }

        // Include host details and explicit links in the response
        $responseBody = [
            'zoom' => $data,
            'host_name' => $user->name ?? null,
            'host_email' => $user->email ?? null,
            'start_url' => $data['start_url'] ?? null,
            'join_url' => $data['join_url'] ?? null,
        ];

        return response()->json($responseBody, 201);
    }

    /**
     * Resolve hub vs partner tenant for Zoom management APIs.
     * Auth is email-based (user_email), not Sanctum — never default missing actors to main admin.
     *
     * @return array{actor: ?User, institutionId: ?int, isMainAdmin: bool}
     */
    private function resolveManagementTenant(Request $request): array
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request) ?: $request->user();
        if (!$actor instanceof User) {
            return ['actor' => null, 'institutionId' => null, 'isMainAdmin' => false];
        }

        $actor = PlatformInstitutionHelper::restorePartnerOwnerRole($actor);
        $isMainAdmin = PlatformInstitutionHelper::isMainPlatformAdmin($actor);

        $institutionId = null;
        if (!$isMainAdmin && !empty($actor->platform_institution_id)) {
            $institutionId = (int) $actor->platform_institution_id;
        }

        // Partner must have an institution — otherwise they see nothing (never hub).
        if (!$isMainAdmin && (!$institutionId || $institutionId <= 0)) {
            return ['actor' => $actor, 'institutionId' => null, 'isMainAdmin' => false];
        }

        return [
            'actor' => $actor,
            'institutionId' => $isMainAdmin ? null : $institutionId,
            'isMainAdmin' => $isMainAdmin,
        ];
    }
}


