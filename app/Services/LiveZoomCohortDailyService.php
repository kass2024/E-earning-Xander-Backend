<?php

namespace App\Services;

use App\Data\Meetings\MeetingJoinRequest;
use App\Enums\MeetingProvider;
use App\Models\LiveZoomCohort;
use App\Models\PlatformInstitution;
use App\Services\Meetings\DailyApiService;
use App\Services\Meetings\MeetingProviderManager;
use App\Services\PlatformSettingsService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LiveZoomCohortDailyService
{
    public function __construct(
        private readonly DailyApiService $daily,
        private readonly MeetingProviderManager $providers,
        private readonly PlatformSettingsService $platformSettings,
    ) {}

    public function resolveProvider(LiveZoomCohort $cohort): MeetingProvider
    {
        // Already provisioned Daily room for this session → stay on Daily.
        if (trim((string) ($cohort->daily_room_name ?? '')) !== '') {
            return MeetingProvider::Daily;
        }

        $institution = null;
        if (!empty($cohort->platform_institution_id)) {
            $institution = PlatformInstitution::find((int) $cohort->platform_institution_id);
        }

        // Institution (or main-platform) setting is the source of truth for live cohorts.
        $institutionDefault = $this->providers->institutionProvider($institution);
        if ($institutionDefault === MeetingProvider::Daily && $this->daily->isConfigured() && (bool) config('daily.enabled', false)) {
            return MeetingProvider::Daily;
        }

        if (Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
            $stored = MeetingProvider::tryFromString($cohort->meeting_provider ?? null);
            if ($stored) {
                return $stored;
            }
        }

        if (trim((string) ($cohort->zoom_meeting_id ?? '')) !== '') {
            return MeetingProvider::Zoom;
        }

        return $institutionDefault;
    }

    /**
     * Persist the institution/main-platform meeting provider onto the cohort before start.
     */
    public function syncProviderFromInstitution(LiveZoomCohort $cohort): LiveZoomCohort
    {
        if (!Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
            return $cohort;
        }

        $provider = $this->defaultProviderForNewCohort(
            $cohort->platform_institution_id ? (int) $cohort->platform_institution_id : null
        );

        if ((string) ($cohort->meeting_provider ?? '') !== $provider->value) {
            $cohort->meeting_provider = $provider->value;
            $cohort->save();
        }

        return $cohort->fresh() ?? $cohort;
    }

    public function usesDaily(LiveZoomCohort $cohort): bool
    {
        return $this->resolveProvider($cohort) === MeetingProvider::Daily;
    }

    /**
     * @return array{ok: bool, reused?: bool, message?: string, daily?: array<string, mixed>}
     */
    public function ensureDailyRoom(LiveZoomCohort $cohort): array
    {
        if (!$this->daily->isConfigured() || !(bool) config('daily.enabled', false)) {
            return [
                'ok' => false,
                'message' => 'Daily is not configured. Set DAILY_INTEGRATION_ENABLED=true and DAILY_API_KEY.',
            ];
        }

        $existingName = trim((string) ($cohort->daily_room_name ?? ''));
        $existingUrl = trim((string) ($cohort->daily_room_url ?? ''));
        if ($existingName !== '' && $existingUrl === '') {
            $existingUrl = $this->daily->roomUrl($existingName);
            $cohort->daily_room_url = $existingUrl;
            $cohort->save();
        }
        if ($existingName !== '' && $existingUrl !== '') {
            if ($this->isDailyRoomReusable($existingName)) {
                // Keep room alive for long teaching sessions.
                $this->extendDailyRoomExpiry($existingName, $cohort);

                return [
                    'ok' => true,
                    'reused' => true,
                    'daily' => $this->formatDailyPayload($cohort->fresh() ?? $cohort),
                ];
            }

            // Room missing/expired on Daily — recreate.
            $cohort->daily_room_name = null;
            $cohort->daily_room_url = null;
            $cohort->save();
        }

        $this->daily->ensureDomainDefaults();

        $institutionId = (int) ($cohort->platform_institution_id ?? 0);
        // Unique room per institution + cohort so many institutions can host at once.
        // Main platform (no institution id) uses "main" so it never collides with institution 1.
        $instPart = $institutionId > 0 ? (string) $institutionId : 'main';
        $roomName = 'cohort-' . $instPart . '-' . $cohort->id . '-' . Str::lower(Str::random(8));

        $exp = now()->addHours(12)->addMinutes((int) config('daily.room_grace_minutes', 30))->timestamp;

        try {
            $roomProps = [
                'exp' => $exp,
            ];
            if ((bool) config('daily.recording_enabled', false) || (bool) config('daily.enabled', false)) {
                $roomProps['enable_recording'] = 'cloud';
            }
            $room = $this->daily->createRoom(
                $roomName,
                $this->daily->classroomRoomProperties($roomProps),
            );
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Could not create Daily room: ' . $e->getMessage(),
            ];
        }

        $resolvedName = (string) ($room['name'] ?? $roomName);
        $roomUrl = (string) ($room['url'] ?? $this->daily->roomUrl($resolvedName));

        $updates = [
            'daily_room_name' => $resolvedName,
            'daily_room_url' => $roomUrl,
            'zoom_link' => $roomUrl,
        ];
        if (Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
            $updates['meeting_provider'] = MeetingProvider::Daily->value;
        }

        $cohort->fill($updates);
        $cohort->save();

        return [
            'ok' => true,
            'reused' => false,
            'daily' => $this->formatDailyPayload($cohort->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSdkPayload(LiveZoomCohort $cohort, string $userName, string $userId, bool $isOwner): array
    {
        $roomName = trim((string) ($cohort->daily_room_name ?? ''));
        $roomUrl = trim((string) ($cohort->daily_room_url ?? ''));
        if ($roomName === '' || $roomUrl === '') {
            throw new \RuntimeException('Daily room is not ready for this cohort. Start the session again.');
        }

        $provider = $this->providers->forProvider(MeetingProvider::Daily);
        $join = $provider->buildJoinDetails(new MeetingJoinRequest(
            externalMeetingId: $roomName,
            roomUrl: $roomUrl,
            userName: $userName,
            userId: $userId,
            isOwner: $isOwner,
            platformInstitutionId: $cohort->platform_institution_id
                ? (int) $cohort->platform_institution_id
                : null,
            expiresAt: now()->addHours(4),
            context: [
                'meeting_role' => $isOwner
                    ? \App\Services\Meetings\DailyPermissionPolicy::ROLE_HOST
                    : \App\Services\Meetings\DailyPermissionPolicy::ROLE_ATTENDEE,
                'meeting_mode' => \App\Services\Meetings\DailyPermissionPolicy::MODE_MEETING,
            ],
        ));

        return [
            'provider' => MeetingProvider::Daily->value,
            'join_url' => $join->joinUrl,
            'token' => $join->token,
            'room_name' => $roomName,
            'role' => $isOwner ? 1 : 0,
            'meeting_role' => $join->metadata['meeting_role'] ?? ($isOwner ? 'host' : 'attendee'),
            'meeting_mode' => 'meeting',
            'user_name' => $userName,
            'permissions' => $join->metadata['permissions'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatDailyPayload(LiveZoomCohort $cohort): array
    {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $dayLabel = $dayNames[(int) $cohort->day_of_week] ?? 'Cohort';
        $topic = trim((string) ($cohort->notes ?? '')) ?: "Live Cohort — {$dayLabel}";
        $roomName = trim((string) ($cohort->daily_room_name ?? ''));
        $publicJoin = \App\Support\LiveZoomCohortHelper::publicJoinUrl($cohort);
        $hostStudio = \App\Support\LiveZoomCohortHelper::hostStudioUrl($cohort);
        $participantPath = \App\Support\LiveZoomCohortHelper::participantRoomPath($cohort);
        $participantUrl = \App\Support\LiveZoomCohortHelper::participantRoomUrl($cohort);

        $shareLines = [
            (string) config('app.name', 'Xander Learning Hub') . ' — Live Cohort (Daily)',
            "Topic: {$topic}",
            $roomName !== '' ? "Room: {$roomName}" : null,
            $publicJoin ? "Public join (queue): {$publicJoin}" : null,
            $participantUrl ? "In-app join: {$participantUrl}" : null,
            "Schedule: {$dayLabel}, " . substr((string) $cohort->start_time, 0, 5) . ' – ' . substr((string) $cohort->end_time, 0, 5)
                . ($cohort->timezone ? " ({$cohort->timezone})" : ''),
            'Hosts and learners join inside the web app — no external Daily links required.',
        ];

        $shareText = implode("\n", array_values(array_filter($shareLines)));

        return [
            'provider' => MeetingProvider::Daily->value,
            'topic' => $topic,
            'meeting_id' => $roomName !== '' ? $roomName : null,
            'room_name' => $roomName !== '' ? $roomName : null,
            'room_url' => $cohort->daily_room_url,
            'join_url' => $publicJoin,
            'start_url' => $hostStudio,
            'password' => null,
            'description' => $shareText,
            'share_text' => $shareText,
            'public_join_url' => $publicJoin,
            'embed_enabled' => true,
            'host_studio_url' => $hostStudio,
            'host_studio_path' => \App\Support\LiveZoomCohortHelper::hostStudioPath($cohort),
            'participant_room_path' => $participantPath,
            'participant_room_url' => $participantUrl,
            'schedule' => [
                'day' => $dayLabel,
                'start_time' => $cohort->start_time,
                'end_time' => $cohort->end_time,
                'timezone' => $cohort->timezone,
            ],
        ];
    }

    public function defaultProviderForNewCohort(?int $institutionId = null): MeetingProvider
    {
        $institution = $institutionId ? PlatformInstitution::find($institutionId) : null;

        return $this->providers->institutionProvider($institution);
    }

    protected function isDailyRoomReusable(string $roomName): bool
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

    protected function extendDailyRoomExpiry(string $roomName, LiveZoomCohort $cohort): void
    {
        try {
            $exp = now()->addHours(12)->addMinutes((int) config('daily.room_grace_minutes', 30))->timestamp;
            $this->daily->updateRoom($roomName, $this->daily->classroomRoomProperties([
                'exp' => $exp,
            ]));
        } catch (\Throwable $e) {
            // Non-fatal — existing room can still be joined until its current exp.
            \Illuminate\Support\Facades\Log::warning('Daily room expiry extend failed', [
                'room' => $roomName,
                'cohort_id' => $cohort->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function durationMinutes(LiveZoomCohort $cohort): int
    {
        try {
            $start = \Carbon\Carbon::parse((string) $cohort->start_time);
            $end = \Carbon\Carbon::parse((string) $cohort->end_time);
            $mins = max(15, $start->diffInMinutes($end));

            return min(480, $mins);
        } catch (\Throwable) {
            return 60;
        }
    }
}
