<?php

namespace App\Services\Meetings;

use App\Contracts\MeetingProviderInterface;
use App\Data\Meetings\MeetingCreateRequest;
use App\Data\Meetings\MeetingCreateResult;
use App\Data\Meetings\MeetingJoinRequest;
use App\Data\Meetings\MeetingJoinResult;
use App\Enums\MeetingProvider;
use App\Exceptions\Meetings\MeetingCreationException;
use App\Exceptions\Meetings\ProviderNotConfiguredException;

class DailyMeetingProvider implements MeetingProviderInterface
{
    public function __construct(
        private readonly DailyApiService $daily,
    ) {}

    public function provider(): MeetingProvider
    {
        return MeetingProvider::Daily;
    }

    public function isConfigured(): bool
    {
        return $this->daily->isConfigured()
            && (bool) config('daily.enabled', config('services.daily.integration_enabled', false));
    }

    public function createMeeting(MeetingCreateRequest $request): MeetingCreateResult
    {
        if (!$this->isConfigured()) {
            throw ProviderNotConfiguredException::forProvider(MeetingProvider::Daily->value);
        }

        $institutionId = (int) ($request->platformInstitutionId ?? 0);
        $courseId = (int) ($request->courseId ?? 0);
        $roomName = $this->daily->generateRoomName(
            $institutionId > 0 ? $institutionId : 1,
            $courseId > 0 ? $courseId : 1,
            $request->materialId,
            $request->actorUserId,
        );

        $grace = (int) config('daily.room_grace_minutes', 30);
        $expiresAt = $request->startAt->copy()->addMinutes($request->durationMinutes + $grace)->timestamp;

        $properties = [
            'exp' => $expiresAt,
            // SFU is Daily's default and correct for classrooms — never force P2P.
            // https://docs.daily.co/docs/guides/architecture-and-monitoring/intro-to-video-arch
        ];
        $properties = $this->daily->classroomRoomProperties(array_merge($properties, [
            'start_audio_off' => $request->muteUponEntry,
        ]));

        if ($request->autoRecording && (bool) config('daily.recording_enabled', false)) {
            $properties['enable_recording'] = 'cloud';
        }

        $this->daily->ensureDomainDefaults();

        try {
            $room = $this->daily->createRoom($roomName, $properties);
        } catch (\Throwable $e) {
            throw new MeetingCreationException('Daily room could not be created: ' . $e->getMessage(), 0, $e);
        }

        $resolvedName = (string) ($room['name'] ?? $roomName);
        $roomUrl = (string) ($room['url'] ?? $this->daily->roomUrl($resolvedName));

        return new MeetingCreateResult(
            provider: MeetingProvider::Daily,
            externalMeetingId: $resolvedName,
            meetingUrl: $roomUrl,
            hostUrl: $roomUrl,
            password: null,
            recordingFallback: false,
            metadata: [
                'daily_room_name' => $resolvedName,
                'daily_room_url' => $roomUrl,
                'daily_exp' => $expiresAt,
            ],
        );
    }

    public function deleteMeeting(string $externalMeetingId, array $context = []): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return $this->daily->deleteRoom($externalMeetingId);
    }

    public function buildJoinDetails(MeetingJoinRequest $request): MeetingJoinResult
    {
        if (!$this->isConfigured()) {
            throw ProviderNotConfiguredException::forProvider(MeetingProvider::Daily->value);
        }

        $tokenGrace = (int) config('daily.token_grace_minutes', 15);
        $expiresAt = $request->expiresAt ?? now()->addHours(4);
        $exp = $expiresAt instanceof \DateTimeInterface ? $expiresAt->getTimestamp() : now()->addHours(4)->timestamp;
        $nbf = now()->subMinutes($tokenGrace)->timestamp;
        $recordingEnabled = (bool) config('daily.recording_enabled', false)
            || (bool) config('daily.enabled', false);

        $tokenProps = [
            'room_name' => $request->externalMeetingId,
            'is_owner' => $request->isOwner,
            'user_name' => $request->userName,
            'user_id' => (string) $request->userId,
            'exp' => $exp,
            'nbf' => $nbf,
            'eject_at_token_exp' => true,
            'enable_screenshare' => true,
            'start_video_off' => true,
            'start_audio_off' => false,
            'lang' => (string) config('daily.default_language', 'en'),
            'enable_recording_ui' => $request->isOwner && $recordingEnabled,
        ];

        if ($request->isOwner && $recordingEnabled) {
            $tokenProps['enable_recording'] = 'cloud';
        }

        $token = $this->daily->createMeetingToken($tokenProps);

        return new MeetingJoinResult(
            provider: MeetingProvider::Daily,
            joinUrl: $request->roomUrl,
            role: $request->isOwner ? 'host' : 'participant',
            token: $token,
            externalMeetingId: $request->externalMeetingId,
            metadata: [
                'room_name' => $request->externalMeetingId,
            ],
        );
    }

    public function supportsRecording(): bool
    {
        return true;
    }

    public function startRecording(string $externalMeetingId, array $context = []): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $result = $this->daily->startRoomRecording($externalMeetingId, is_array($context['options'] ?? null) ? $context['options'] : []);

        return !empty($result['ok']);
    }

    public function stopRecording(string $externalMeetingId, array $context = []): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $result = $this->daily->stopRoomRecording($externalMeetingId);

        return !empty($result['ok']);
    }

    /**
     * Full start/stop result for API controllers.
     *
     * @return array{ok: bool, message?: string, result?: array<string, mixed>}
     */
    public function toggleCloudRecording(string $roomName, string $action): array
    {
        $action = strtolower(trim($action));
        if (in_array($action, ['start', 'resume'], true)) {
            $result = $this->daily->startRoomRecording($roomName);

            return [
                'ok' => !empty($result['ok']),
                'message' => $result['message'] ?? null,
                'result' => $result['result'] ?? $result,
            ];
        }

        if (in_array($action, ['stop', 'pause'], true)) {
            $result = $this->daily->stopRoomRecording($roomName);

            return [
                'ok' => !empty($result['ok']),
                'message' => $result['message'] ?? null,
                'result' => $result['result'] ?? $result,
            ];
        }

        return ['ok' => false, 'message' => 'Unsupported recording action.'];
    }
}
