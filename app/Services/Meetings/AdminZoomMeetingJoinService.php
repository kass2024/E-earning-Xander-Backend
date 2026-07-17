<?php

namespace App\Services\Meetings;

use App\Data\Meetings\MeetingJoinRequest;
use App\Enums\MeetingProvider;
use App\Models\AdminZoomMeeting;
use Carbon\Carbon;

/**
 * Join auth for admin-scheduled meetings/webinars stored in admin_zoom_meetings (Daily rooms).
 */
class AdminZoomMeetingJoinService
{
    public function __construct(
        private readonly DailyApiService $daily,
        private readonly MeetingProviderManager $providers,
    ) {}

    public function findByRoomName(string $roomName): ?AdminZoomMeeting
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return null;
        }

        return AdminZoomMeeting::query()
            ->where(function ($query) use ($roomName) {
                $query->where('zoom_meeting_id', $roomName)
                    ->orWhere('daily_room_name', $roomName);
            })
            ->first();
    }

    public function isDailyMeeting(AdminZoomMeeting $meeting): bool
    {
        $provider = strtolower(trim((string) ($meeting->meeting_provider ?? '')));
        if ($provider === MeetingProvider::Daily->value) {
            return true;
        }

        $room = trim((string) ($meeting->daily_room_name ?: $meeting->zoom_meeting_id ?? ''));
        if ($room === '') {
            return false;
        }

        if ($this->looksLikeDailyRoomName($room)) {
            return true;
        }

        $join = trim((string) ($meeting->join_url ?? ''));
        if ($join !== '' && str_contains(strtolower($join), 'daily.co')) {
            return true;
        }

        return false;
    }

    public function looksLikeDailyRoomName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        foreach (['admin-meet-', 'admin-webinar-', 'webinar-', 'daily-', 'inst-', 'cohort-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return !preg_match('/^\d{9,15}$/', $name);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSdkPayload(AdminZoomMeeting $meeting, string $userName, string $userId, bool $isOwner): array
    {
        $meeting = $this->ensureRoomActive($meeting);

        $roomName = trim((string) ($meeting->daily_room_name ?: $meeting->zoom_meeting_id ?? ''));
        $roomUrl = trim((string) ($meeting->daily_room_url ?: $meeting->join_url ?? ''));
        if ($roomName === '') {
            throw new \RuntimeException('Daily room is not configured for this session.');
        }
        if ($roomUrl === '' || !str_contains(strtolower($roomUrl), 'daily.co')) {
            $roomUrl = $this->daily->roomUrl($roomName);
        }

        $mode = strtolower(trim((string) ($meeting->meeting_mode ?? $meeting->meta['meeting_mode'] ?? $meeting->meta['type'] ?? 'meeting')));
        $meetingMode = $mode === DailyPermissionPolicy::MODE_WEBINAR
            ? DailyPermissionPolicy::MODE_WEBINAR
            : DailyPermissionPolicy::MODE_MEETING;

        $duration = max(30, (int) ($meeting->duration ?? 60));
        $scheduledAt = $meeting->start_time ? Carbon::parse($meeting->start_time) : now();
        $expiresAt = $scheduledAt->copy()->addMinutes($duration + 120);
        if ($expiresAt->lessThan(now()->addHour())) {
            $expiresAt = now()->addHours(4);
        }

        $provider = $this->providers->forProvider(MeetingProvider::Daily);
        $join = $provider->buildJoinDetails(new MeetingJoinRequest(
            externalMeetingId: $roomName,
            roomUrl: $roomUrl,
            userName: $userName,
            userId: $userId,
            isOwner: $isOwner,
            platformInstitutionId: null,
            expiresAt: $expiresAt,
            context: [
                'meeting_role' => $isOwner
                    ? DailyPermissionPolicy::ROLE_HOST
                    : DailyPermissionPolicy::ROLE_ATTENDEE,
                'meeting_mode' => $meetingMode,
            ],
        ));

        return [
            'provider' => MeetingProvider::Daily->value,
            'join_url' => $join->joinUrl,
            'token' => $join->token,
            'room_name' => $roomName,
            'role' => $isOwner ? 1 : 0,
            'meeting_role' => $join->metadata['meeting_role'] ?? ($isOwner ? 'host' : 'attendee'),
            'meeting_mode' => $meetingMode,
            'user_name' => $userName,
            'permissions' => $join->metadata['permissions'] ?? null,
        ];
    }

    public function deleteDailyRoom(AdminZoomMeeting $meeting): void
    {
        $roomName = trim((string) ($meeting->daily_room_name ?: $meeting->zoom_meeting_id ?? ''));
        if ($roomName === '') {
            return;
        }

        try {
            $this->daily->deleteRoom($roomName);
        } catch (\Throwable) {
            // Room may already be gone.
        }
    }

    protected function ensureRoomActive(AdminZoomMeeting $meeting): AdminZoomMeeting
    {
        $roomName = trim((string) ($meeting->daily_room_name ?: $meeting->zoom_meeting_id ?? ''));
        if ($roomName === '') {
            return $meeting;
        }

        if ($this->isRoomReusable($roomName)) {
            try {
                $duration = max(30, (int) ($meeting->duration ?? 60));
                $grace = (int) config('daily.room_grace_minutes', 30);
                $this->daily->updateRoom($roomName, $this->daily->classroomRoomProperties([
                    'exp' => now()->addMinutes($duration + $grace)->timestamp,
                ]));
            } catch (\Throwable) {
                // non-fatal
            }

            return $meeting->fresh() ?? $meeting;
        }

        throw new \RuntimeException('This Daily room has expired. Schedule a new session from the dashboard.');
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
