<?php

namespace App\Services\Meetings;

use App\Enums\MeetingProvider;
use App\Models\CourseMaterial;
use App\Models\LiveMeetingAttendanceSegment;
use App\Models\ProviderWebhookEvent;
use App\Support\CourseMaterialHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DailyWebhookEventDispatcher
{
    public function dispatch(ProviderWebhookEvent $event): void
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $type = (string) ($event->event_type ?? $payload['type'] ?? '');
        $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;

        match ($type) {
            'meeting.started' => $this->handleMeetingStarted($data),
            'meeting.ended' => $this->handleMeetingEnded($data),
            'participant.joined' => $this->handleParticipantJoined($data),
            'participant.left' => $this->handleParticipantLeft($data),
            'recording.started' => $this->handleRecordingStarted($data),
            'recording.ready-to-download' => $this->handleRecordingReady($data),
            'recording.error' => $this->handleRecordingError($data),
            default => Log::info('Daily webhook event ignored', [
                'event_type' => $type,
                'external_event_id' => $event->external_event_id,
            ]),
        };
    }

    /** @param array<string, mixed> $data */
    protected function handleMeetingStarted(array $data): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $meta['provider_started_at'] = $data['start_time'] ?? now()->toIso8601String();
        if (!empty($data['id'])) {
            $meta['daily_meeting_id'] = (string) $data['id'];
        }
        if (empty($meta['session_started_at'])) {
            $meta['session_started_at'] = now()->toIso8601String();
        }

        $material->metadata = $meta;
        $material->save();
    }

    /** @param array<string, mixed> $data */
    protected function handleMeetingEnded(array $data): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $meta['provider_ended_at'] = $data['end_time'] ?? now()->toIso8601String();
        if (!empty($data['id'])) {
            $meta['daily_meeting_id'] = (string) $data['id'];
        }
        $meta['recording_status'] = $meta['recording_status'] ?? 'processing';

        $material->metadata = $meta;
        $material->save();
    }

    /** @param array<string, mixed> $data */
    protected function handleParticipantJoined(array $data): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        LiveMeetingAttendanceSegment::query()->create([
            'platform_institution_id' => $material->course?->platform_institution_id,
            'course_id' => $material->course_id,
            'course_material_id' => $material->id,
            'user_id' => $this->resolveLocalUserId($data),
            'provider' => MeetingProvider::Daily->value,
            'provider_session_id' => isset($data['session_id']) ? (string) $data['session_id'] : null,
            'provider_participant_id' => isset($data['participant_id']) ? (string) $data['participant_id'] : null,
            'provider_user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
            'role' => !empty($data['owner']) ? 'host' : 'participant',
            'source' => 'webhook',
            'joined_at' => $this->parseTime($data['joined_at'] ?? null) ?? now(),
            'metadata' => [
                'user_name' => $data['user_name'] ?? null,
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleParticipantLeft(array $data): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        $providerUserId = isset($data['user_id']) ? (string) $data['user_id'] : null;
        $sessionId = isset($data['session_id']) ? (string) $data['session_id'] : null;

        $segment = LiveMeetingAttendanceSegment::query()
            ->where('course_material_id', $material->id)
            ->where('provider', MeetingProvider::Daily->value)
            ->whereNull('left_at')
            ->when($providerUserId, fn ($q) => $q->where('provider_user_id', $providerUserId))
            ->when($sessionId, fn ($q) => $q->where('provider_session_id', $sessionId))
            ->latest('id')
            ->first();

        if (!$segment) {
            LiveMeetingAttendanceSegment::query()->create([
                'platform_institution_id' => $material->course?->platform_institution_id,
                'course_id' => $material->course_id,
                'course_material_id' => $material->id,
                'user_id' => $this->resolveLocalUserId($data),
                'provider' => MeetingProvider::Daily->value,
                'provider_session_id' => $sessionId,
                'provider_user_id' => $providerUserId,
                'role' => !empty($data['owner']) ? 'host' : 'participant',
                'source' => 'webhook',
                'left_at' => $this->parseTime($data['left_at'] ?? null) ?? now(),
                'metadata' => ['reconciled' => true],
            ]);

            return;
        }

        $leftAt = $this->parseTime($data['left_at'] ?? null) ?? now();
        $joinedAt = $segment->joined_at;
        $duration = ($joinedAt && $leftAt->greaterThan($joinedAt))
            ? $joinedAt->diffInSeconds($leftAt)
            : null;

        $segment->forceFill([
            'left_at' => $leftAt,
            'duration_seconds' => $duration,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordingStarted(array $data): void
    {
        $this->updateRecordingStatus($data, 'recording');
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordingReady(array $data): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $meta['recording_status'] = 'ready';
        if (!empty($data['recording_id'])) {
            $meta['recording_provider_id'] = (string) $data['recording_id'];
        }
        $meta['recording_metadata'] = array_filter([
            'recording_id' => $data['recording_id'] ?? null,
            'room_name' => $data['room_name'] ?? null,
        ]);

        $material->metadata = $meta;
        $material->save();
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordingError(array $data): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $meta['recording_status'] = 'failed';
        $meta['recording_metadata'] = array_filter([
            'error' => is_string($data['error'] ?? null) ? $data['error'] : ($data['info'] ?? null),
        ]);

        $material->metadata = $meta;
        $material->save();
    }

    /** @param array<string, mixed> $data */
    protected function updateRecordingStatus(array $data, string $status): void
    {
        $material = $this->resolveMaterial($data);
        if (!$material) {
            return;
        }

        $meta = is_array($material->metadata) ? $material->metadata : [];
        $meta['recording_status'] = $status;
        if (!empty($data['recording_id'])) {
            $meta['recording_provider_id'] = (string) $data['recording_id'];
        }

        $material->metadata = $meta;
        $material->save();
    }

    /** @param array<string, mixed> $data */
    protected function resolveMaterial(array $data): ?CourseMaterial
    {
        $room = (string) ($data['room'] ?? $data['room_name'] ?? '');
        if ($room === '') {
            return null;
        }

        return CourseMaterial::query()
            ->where('type', 'zoom')
            ->where(function ($query) use ($room) {
                $query->where('metadata->daily_room_name', $room)
                    ->orWhere('metadata->meeting_id', $room);
            })
            ->first();
    }

    /** @param array<string, mixed> $data */
    protected function resolveLocalUserId(array $data): ?int
    {
        $userId = $data['user_id'] ?? null;
        if (!is_string($userId) && !is_numeric($userId)) {
            return null;
        }

        $userId = (string) $userId;
        if ($userId === '' || !ctype_digit($userId)) {
            return null;
        }

        return (int) $userId;
    }

    protected function parseTime(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
