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
        if (Schema::hasColumn('livezoom_cohort', 'meeting_provider')) {
            $stored = MeetingProvider::tryFromString($cohort->meeting_provider ?? null);
            if ($stored) {
                return $stored;
            }
        }

        if (trim((string) ($cohort->daily_room_name ?? '')) !== '') {
            return MeetingProvider::Daily;
        }

        $institution = null;
        if (!empty($cohort->platform_institution_id)) {
            $institution = PlatformInstitution::find((int) $cohort->platform_institution_id);
        }

        $default = $this->providers->institutionProvider($institution);

        // Prefer platform default (Daily) when configured — do not stick on a stale Zoom meeting id.
        if ($default === MeetingProvider::Daily && $this->daily->isConfigured()) {
            return MeetingProvider::Daily;
        }

        if (trim((string) ($cohort->zoom_meeting_id ?? '')) !== '') {
            return MeetingProvider::Zoom;
        }

        return $default;
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

    /**
     * @return array<string, mixed>
     */
    public function formatDailyPayload(LiveZoomCohort $cohort): array
    {
        return [
            'provider' => MeetingProvider::Daily->value,
            'room_name' => $cohort->daily_room_name,
            'room_url' => $cohort->daily_room_url,
            'join_url' => $cohort->daily_room_url,
            'public_join_url' => \App\Support\LiveZoomCohortHelper::publicJoinUrl($cohort),
            'host_studio_url' => \App\Support\LiveZoomCohortHelper::hostStudioUrl($cohort),
            'host_studio_path' => \App\Support\LiveZoomCohortHelper::hostStudioPath($cohort),
            'participant_room_path' => \App\Support\LiveZoomCohortHelper::participantRoomPath($cohort),
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
