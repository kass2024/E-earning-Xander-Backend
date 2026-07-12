<?php

namespace App\Data\Meetings;

use App\Enums\MeetingProvider;

class MeetingCreateResult
{
    public function __construct(
        public readonly MeetingProvider $provider,
        public readonly string $externalMeetingId,
        public readonly string $meetingUrl,
        public readonly ?string $hostUrl = null,
        public readonly ?string $password = null,
        public readonly bool $recordingFallback = false,
        public readonly array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'external_meeting_id' => $this->externalMeetingId,
            'meeting_url' => $this->meetingUrl,
            'host_url' => $this->hostUrl,
            'password' => $this->password,
            'recording_fallback' => $this->recordingFallback,
            'metadata' => $this->metadata,
        ];
    }
}
