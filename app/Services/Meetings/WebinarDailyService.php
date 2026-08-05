<?php

namespace App\Services\Meetings;

use App\Data\Meetings\MeetingJoinRequest;
use App\Enums\MeetingProvider;
use App\Models\WebinarSetting;
use App\Services\PlatformSettingsService;
use App\Support\FrontendUrl;
use App\Support\MeetingSettingsMapper;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Daily rooms for Meeting Registration / Pathways webinar sessions.
 * Reuses webinar_settings.zoom_* columns to store the Daily room name/url
 * when the platform meeting provider is Daily.
 */
class WebinarDailyService
{
    public function __construct(
        private readonly DailyApiService $daily,
        private readonly MeetingProviderManager $providers,
        private readonly PlatformSettingsService $platformSettings,
    ) {}

    public function shouldUseDaily(): bool
    {
        if (!$this->daily->isConfigured()) {
            return false;
        }

        return $this->platformSettings->mainPlatformMeetingProvider() === MeetingProvider::Daily;
    }

    public function isDailyWebinar(WebinarSetting $settings): bool
    {
        $room = trim((string) ($settings->zoom_meeting_id ?? ''));
        if ($room === '') {
            return false;
        }

        if (str_starts_with($room, 'webinar-') || str_starts_with($room, 'daily-')) {
            return true;
        }

        $join = trim((string) ($settings->zoom_join_url ?? ''));
        if ($join !== '' && str_contains(strtolower($join), 'daily.co')) {
            return true;
        }

        // Numeric Zoom meeting IDs are Zoom-only.
        if (preg_match('/^\d{9,15}$/', $room)) {
            return false;
        }

        return $this->shouldUseDaily() && !preg_match('/^\d+$/', $room);
    }

    public function isDailyRoomName(string $meetingNumber): bool
    {
        $name = trim($meetingNumber);
        if ($name === '') {
            return false;
        }
        if (str_starts_with($name, 'webinar-') || str_starts_with($name, 'daily-') || str_starts_with($name, 'inst-') || str_starts_with($name, 'cohort-')
            || str_starts_with($name, 'admin-meet-') || str_starts_with($name, 'admin-webinar-')) {
            return true;
        }
        if (preg_match('/^\d{9,15}$/', $name)) {
            return false;
        }

        $stored = WebinarSetting::query()
            ->where('zoom_meeting_id', $name)
            ->first();

        return $stored !== null && $this->isDailyWebinar($stored);
    }

    /**
     * Create or reuse a Daily room for the webinar and persist on settings.
     *
     * @return array{ok: bool, settings?: WebinarSetting, message?: string}
     */
    public function ensureRoom(WebinarSetting $settings, ?int $institutionId = null): array
    {
        if (!$this->daily->isConfigured()) {
            return ['ok' => false, 'message' => 'Daily is not configured on this server.'];
        }

        $existing = trim((string) ($settings->zoom_meeting_id ?? ''));
        $webinarSettings = MeetingSettingsMapper::webinarRegistrationDefaults(
            (bool) ($settings->recording_enabled ?? false),
        );

        if ($existing !== '' && $this->isDailyWebinar($settings) && $this->isRoomReusable($existing)) {
            try {
                $this->daily->updateRoom($existing, $this->daily->classroomRoomProperties(
                    MeetingSettingsMapper::dailyRoomProperties($webinarSettings, [
                        'exp' => now()->addHours(12)->timestamp,
                    ]),
                ));
            } catch (\Throwable) {
                // non-fatal
            }

            $this->persistAppUrls($settings, $existing, trim((string) ($settings->zoom_join_url ?? $this->daily->roomUrl($existing))));

            return ['ok' => true, 'settings' => $settings->fresh()];
        }

        try {
            $this->daily->ensureDomainDefaults();
            $roomName = 'webinar-' . ($institutionId && $institutionId > 0 ? $institutionId : 'main') . '-' . Str::lower(Str::random(8));
            $room = $this->daily->createClassroomRoom($roomName, $webinarSettings, [
                'exp' => now()->addHours(12)->timestamp,
            ]);
            $resolvedName = (string) ($room['name'] ?? $roomName);
            $roomUrl = (string) ($room['url'] ?? $this->daily->roomUrl($resolvedName));

            $settings->zoom_meeting_id = $resolvedName;
            $settings->zoom_join_url = $roomUrl;
            $settings->zoom_password = null;
            $this->persistAppUrls($settings, $resolvedName, $roomUrl);
            $settings->save();

            return ['ok' => true, 'settings' => $settings->fresh()];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Unable to create Daily webinar room: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create or reuse a Daily room for a scheduled registration webinar session.
     *
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   join_url?: string|null,
     *   start_url?: string|null,
     *   meeting_id?: string|null,
     *   reused?: bool,
     *   settings?: WebinarSetting
     * }
     */
    public function ensureScheduledRoom(
        WebinarSetting $settings,
        Carbon $startAt,
        int $durationMinutes,
        ?int $institutionId = null,
    ): array {
        if (!$this->daily->isConfigured()) {
            return ['ok' => false, 'message' => 'Daily is not configured on this server.'];
        }

        $sessionKey = $startAt->copy()->utc()->format('Y-m-d H:i');
        $existing = trim((string) ($settings->zoom_meeting_id ?? ''));

        if (
            $existing !== ''
            && $this->isDailyWebinar($settings)
            && $settings->zoom_join_url
            && $settings->zoom_scheduled_at
            && $sessionKey === Carbon::parse($settings->zoom_scheduled_at)->utc()->format('Y-m-d H:i')
            && $this->isRoomReusable($existing)
        ) {
            return [
                'ok' => true,
                'join_url' => $settings->zoom_join_url,
                'start_url' => $settings->zoom_start_url,
                'meeting_id' => $existing,
                'reused' => true,
                'settings' => $settings,
            ];
        }

        $webinarSettings = MeetingSettingsMapper::webinarRegistrationDefaults(
            (bool) ($settings->recording_enabled ?? false),
        );

        $grace = (int) config('daily.room_grace_minutes', 30);
        $expAt = $startAt->copy()->addMinutes(max(1, $durationMinutes) + $grace);
        $minExp = now()->addMinutes(max(1, $durationMinutes) + $grace);
        if ($expAt->lt($minExp)) {
            $expAt = $minExp;
        }

        try {
            $this->daily->ensureDomainDefaults();
            $roomName = 'webinar-' . ($institutionId && $institutionId > 0 ? $institutionId : 'main')
                . '-' . Str::lower(Str::random(8));
            $room = $this->daily->createClassroomRoom($roomName, $webinarSettings, [
                'exp' => $expAt->timestamp,
            ]);
            $resolvedName = (string) ($room['name'] ?? $roomName);
            $roomUrl = (string) ($room['url'] ?? $this->daily->roomUrl($resolvedName));

            $settings->zoom_meeting_id = $resolvedName;
            $settings->zoom_scheduled_at = $startAt;
            $settings->zoom_password = null;
            $this->persistAppUrls($settings, $resolvedName, $roomUrl);
            $settings->save();

            return [
                'ok' => true,
                'join_url' => $this->appParticipantJoinUrl($resolvedName),
                'start_url' => $settings->zoom_start_url,
                'meeting_id' => $resolvedName,
                'reused' => false,
                'settings' => $settings->fresh(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Unable to create Daily webinar room: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSdkPayload(WebinarSetting $settings, string $userName, string $userId, bool $isOwner): array
    {
        $roomName = trim((string) ($settings->zoom_meeting_id ?? ''));
        $roomUrl = trim((string) ($settings->zoom_join_url ?? ''));
        if ($roomName === '') {
            throw new \RuntimeException('Daily webinar room is not ready. Start the webinar first.');
        }
        if ($roomUrl === '' || !str_contains(strtolower($roomUrl), 'daily.co')) {
            $roomUrl = $this->daily->roomUrl($roomName);
        }

        if (!$this->isRoomReusable($roomName)) {
            $institutionId = $settings->platform_institution_id
                ? (int) $settings->platform_institution_id
                : null;
            $ensured = $this->ensureRoom($settings, $institutionId);
            if (!$ensured['ok']) {
                throw new \RuntimeException($ensured['message'] ?? 'Daily webinar room expired.');
            }
            $settings = $ensured['settings'];
            $roomName = trim((string) ($settings->zoom_meeting_id ?? ''));
            $roomUrl = trim((string) ($settings->zoom_join_url ?? $this->daily->roomUrl($roomName)));
        }

        $provider = $this->providers->forProvider(MeetingProvider::Daily);
        $webinarSettings = MeetingSettingsMapper::webinarRegistrationDefaults(
            (bool) ($settings->recording_enabled ?? false),
        );
        $join = $provider->buildJoinDetails(new MeetingJoinRequest(
            externalMeetingId: $roomName,
            roomUrl: $roomUrl,
            userName: $userName,
            userId: $userId,
            isOwner: $isOwner,
            platformInstitutionId: $settings->platform_institution_id
                ? (int) $settings->platform_institution_id
                : null,
            expiresAt: now()->addHours(4),
            context: [
                'meeting_role' => $isOwner
                    ? \App\Services\Meetings\DailyPermissionPolicy::ROLE_HOST
                    : \App\Services\Meetings\DailyPermissionPolicy::ROLE_ATTENDEE,
                'meeting_mode' => \App\Services\Meetings\DailyPermissionPolicy::MODE_WEBINAR,
                'meeting_settings' => $webinarSettings,
            ],
        ));

        if ($isOwner && !empty($webinarSettings['auto_recording'])) {
            try {
                $this->daily->startRoomRecording($roomName);
            } catch (\Throwable) {
                // Host can start recording manually from the toolbar.
            }
        }

        return [
            'provider' => MeetingProvider::Daily->value,
            'join_url' => $join->joinUrl,
            'token' => $join->token,
            'room_name' => $roomName,
            'role' => $isOwner ? 1 : 0,
            'meeting_role' => $join->metadata['meeting_role'] ?? ($isOwner ? 'host' : 'attendee'),
            'meeting_mode' => 'webinar',
            'user_name' => $userName,
            'permissions' => $join->metadata['permissions'] ?? null,
        ];
    }

    public function hostRoomPath(): string
    {
        return '/meeting/room?webinar_host=1&role=1';
    }

    public function participantRoomPath(string $roomName): string
    {
        return '/meeting/room?meeting_number=' . rawurlencode($roomName) . '&role=0';
    }

    public function appParticipantJoinUrl(string $roomName): string
    {
        return rtrim(FrontendUrl::base(), '/') . $this->participantRoomPath($roomName);
    }

    public function appHostRoomUrl(): string
    {
        return rtrim(FrontendUrl::base(), '/') . $this->hostRoomPath();
    }

    protected function persistAppUrls(WebinarSetting $settings, string $roomName, string $dailyRoomUrl): void
    {
        // Keep Daily room URL in join_url for token building; host start uses in-app path.
        if ($dailyRoomUrl !== '' && str_contains(strtolower($dailyRoomUrl), 'daily.co')) {
            $settings->zoom_join_url = $dailyRoomUrl;
        } elseif (trim((string) ($settings->zoom_join_url ?? '')) === '') {
            $settings->zoom_join_url = $this->daily->roomUrl($roomName);
        }
        $settings->zoom_start_url = $this->appHostRoomUrl();
        $settings->zoom_meeting_id = $roomName;
    }

    protected function isRoomReusable(string $roomName): bool
    {
        try {
            $info = $this->daily->get('/rooms/' . rawurlencode($roomName));
            $exp = $info['config']['exp'] ?? ($info['exp'] ?? null);
            if ($exp !== null && (int) $exp <= time() + 120) {
                return false;
            }

            return !empty($info['name']);
        } catch (\Throwable) {
            return false;
        }
    }
}
