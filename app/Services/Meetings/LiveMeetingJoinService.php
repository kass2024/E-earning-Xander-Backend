<?php

namespace App\Services\Meetings;

use App\Data\Meetings\MeetingJoinRequest;
use App\Enums\MeetingProvider;
use App\Models\CourseMaterial;
use App\Support\CourseMaterialHelper;
use Carbon\Carbon;

class LiveMeetingJoinService
{
    public function __construct(
        private readonly MeetingProviderManager $manager,
        private readonly DailyApiService $daily,
    ) {}

    public function usesDaily(CourseMaterial $material): bool
    {
        return CourseMaterialHelper::meetingProvider($material) === MeetingProvider::Daily;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDailySdkPayload(
        CourseMaterial $material,
        string $userName,
        string $userId,
        bool $isOwner,
    ): array {
        $meta = is_array($material->metadata) ? $material->metadata : [];
        $roomName = trim((string) (
            CourseMaterialHelper::externalMeetingReference($material)
            ?? CourseMaterialHelper::meetingId($material)
            ?? ($meta['daily_room_name'] ?? '')
        ));
        $roomUrl = trim((string) ($meta['daily_room_url'] ?? $meta['join_url'] ?? $material->resource_url ?? ''));

        if ($roomName !== '' && $roomUrl === '') {
            $roomUrl = $this->daily->roomUrl($roomName);
        }

        if ($roomName === '' || $roomUrl === '') {
            throw new \RuntimeException('Daily room is not configured for this session. Recreate the live class meeting.');
        }

        // If the Daily room expired/missing, recreate it for this material.
        if (!$this->isRoomReusable($roomName)) {
            $recreated = $this->recreateMaterialRoom($material, $roomName);
            $roomName = $recreated['name'];
            $roomUrl = $recreated['url'];
        } else {
            try {
                $this->daily->updateRoom($roomName, $this->daily->classroomRoomProperties([
                    'exp' => now()->addHours(12)->timestamp,
                ]));
            } catch (\Throwable) {
                // non-fatal
            }
        }

        $duration = (int) ($meta['duration'] ?? 60);
        $scheduledAt = $material->scheduled_at ? Carbon::parse($material->scheduled_at) : now();
        $expiresAt = $scheduledAt->copy()->addMinutes(max(60, $duration) + 120);
        if ($expiresAt->lessThan(now()->addHour())) {
            $expiresAt = now()->addHours(4);
        }

        $provider = $this->manager->forProvider(MeetingProvider::Daily);
        $join = $provider->buildJoinDetails(new MeetingJoinRequest(
            externalMeetingId: $roomName,
            roomUrl: $roomUrl,
            userName: $userName,
            userId: $userId,
            isOwner: $isOwner,
            platformInstitutionId: isset($meta['platform_institution_id']) ? (int) $meta['platform_institution_id'] : null,
            expiresAt: $expiresAt,
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

    /**
     * @return array{name: string, url: string}
     */
    protected function recreateMaterialRoom(CourseMaterial $material, string $oldRoomName): array
    {
        try {
            $this->daily->deleteRoom($oldRoomName);
        } catch (\Throwable) {
            // ignore
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $institutionId = (int) ($meta['platform_institution_id'] ?? 1);
        $courseId = (int) ($material->course_id ?? 1);
        $hostUserId = isset($meta['host_user_id']) ? (int) $meta['host_user_id'] : null;
        $roomName = $this->daily->generateRoomName(
            max(1, $institutionId),
            max(1, $courseId),
            $material->id,
            $hostUserId && $hostUserId > 0 ? $hostUserId : null,
        );
        $this->daily->ensureDomainDefaults();
        $room = $this->daily->createRoom($roomName, $this->daily->classroomRoomProperties([
            'exp' => now()->addHours(12)->timestamp,
        ]));
        $resolvedName = (string) ($room['name'] ?? $roomName);
        $roomUrl = (string) ($room['url'] ?? $this->daily->roomUrl($resolvedName));

        $meta['daily_room_name'] = $resolvedName;
        $meta['daily_room_url'] = $roomUrl;
        $meta['meeting_id'] = $resolvedName;
        $meta['join_url'] = $roomUrl;
        $material->metadata = $meta;
        $material->resource_url = $roomUrl;
        $material->save();

        return ['name' => $resolvedName, 'url' => $roomUrl];
    }
}
