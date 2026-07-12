<?php

namespace App\Services\Meetings;

use App\Data\Meetings\MeetingJoinRequest;
use App\Enums\MeetingProvider;
use App\Models\WebinarSetting;
use App\Services\PlatformSettingsService;
use App\Support\FrontendUrl;
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
        if (str_starts_with($name, 'webinar-') || str_starts_with($name, 'daily-') || str_starts_with($name, 'inst-') || str_starts_with($name, 'cohort-')) {
            return true;
        }
        if (preg_match('/^\d{9,15}$/', $name)) {
            return false;
        }

        $settings = WebinarSetting::current();
        $stored = trim((string) ($settings->zoom_meeting_id ?? ''));

        return $stored !== '' && $stored === $name && $this->isDailyWebinar($settings);
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
        if ($existing !== '' && $this->isDailyWebinar($settings) && $this->isRoomReusable($existing)) {
            try {
                $this->daily->updateRoom($existing, $this->daily->classroomRoomProperties([
                    'exp' => now()->addHours(12)->timestamp,
                ]));
            } catch (\Throwable) {
                // non-fatal
            }

            $this->persistAppUrls($settings, $existing, trim((string) ($settings->zoom_join_url ?? $this->daily->roomUrl($existing))));

            return ['ok' => true, 'settings' => $settings->fresh()];
        }

        try {
            $this->daily->ensureDomainDefaults();
            $roomName = 'webinar-' . ($institutionId && $institutionId > 0 ? $institutionId : 'main') . '-' . Str::lower(Str::random(8));
            $room = $this->daily->createRoom($roomName, $this->daily->classroomRoomProperties([
                'exp' => now()->addHours(12)->timestamp,
            ]));
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
            $ensured = $this->ensureRoom($settings);
            if (!$ensured['ok']) {
                throw new \RuntimeException($ensured['message'] ?? 'Daily webinar room expired.');
            }
            $settings = $ensured['settings'];
            $roomName = trim((string) ($settings->zoom_meeting_id ?? ''));
            $roomUrl = trim((string) ($settings->zoom_join_url ?? $this->daily->roomUrl($roomName)));
        }

        $provider = $this->providers->forProvider(MeetingProvider::Daily);
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
        ));

        return [
            'provider' => MeetingProvider::Daily->value,
            'join_url' => $join->joinUrl,
            'token' => $join->token,
            'room_name' => $roomName,
            'role' => $isOwner ? 1 : 0,
            'user_name' => $userName,
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
