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
use App\Services\ZoomService;

class ZoomMeetingProvider implements MeetingProviderInterface
{
    public function __construct(
        private readonly ZoomService $zoom,
    ) {}

    public function provider(): MeetingProvider
    {
        return MeetingProvider::Zoom;
    }

    public function isConfigured(): bool
    {
        return $this->zoom->isConfigured();
    }

    public function createMeeting(MeetingCreateRequest $request): MeetingCreateResult
    {
        $status = $this->zoom->configurationStatus();
        if (empty($status['api_ready'])) {
            throw ProviderNotConfiguredException::forProvider(MeetingProvider::Zoom->value);
        }

        $hostId = $this->zoom->resolveHostUserId(
            $request->platformInstitutionId,
            $request->actorUserId,
            $request->actorEmail,
        );

        $payload = [
            'topic' => $request->topic,
            'start_time' => $request->startAt->format('Y-m-d\TH:i:s'),
            'duration' => $request->durationMinutes,
            'timezone' => $request->timezone,
            'agenda' => $request->agenda ?? '',
            'join_before_host' => $request->joinBeforeHost,
            'waiting_room' => $request->waitingRoom,
            'mute_upon_entry' => $request->muteUponEntry,
            'auto_recording' => $request->autoRecording,
        ];

        $zoomData = $this->zoom->createMeeting($payload, $hostId);
        if ($zoomData === null) {
            throw new MeetingCreationException('Zoom meeting could not be created.');
        }

        if (!empty($zoomData['error'])) {
            $message = $this->zoom->formatZoomApiErrorMessage($zoomData['body'] ?? []);
            throw new MeetingCreationException($message ?: 'Zoom rejected meeting creation.');
        }

        $joinUrl = (string) ($zoomData['join_url'] ?? '');
        if ($joinUrl === '') {
            throw new MeetingCreationException('Zoom meeting created but join link was not returned.');
        }

        return new MeetingCreateResult(
            provider: MeetingProvider::Zoom,
            externalMeetingId: (string) ($zoomData['id'] ?? ''),
            meetingUrl: $joinUrl,
            hostUrl: isset($zoomData['start_url']) ? (string) $zoomData['start_url'] : null,
            password: isset($zoomData['password']) ? (string) $zoomData['password'] : null,
            recordingFallback: !empty($zoomData['recording_fallback']),
            metadata: [
                'zoom_host_user_id' => $hostId,
                'join_pwd' => $this->zoom->extractPasswordFromJoinUrl($joinUrl),
            ],
        );
    }

    public function deleteMeeting(string $externalMeetingId, array $context = []): bool
    {
        $result = $this->zoom->deleteMeeting($externalMeetingId);

        return is_array($result) && empty($result['error']);
    }

    public function buildJoinDetails(MeetingJoinRequest $request): MeetingJoinResult
    {
        throw new \BadMethodCallException('Zoom join credentials are produced by ZoomEmbedController / ZoomMeetingSdkService.');
    }

    public function supportsRecording(): bool
    {
        return true;
    }

    public function startRecording(string $externalMeetingId, array $context = []): bool
    {
        return $this->zoom->setMeetingAutoRecording($externalMeetingId, true);
    }

    public function stopRecording(string $externalMeetingId, array $context = []): bool
    {
        return $this->zoom->setMeetingAutoRecording($externalMeetingId, false);
    }
}
