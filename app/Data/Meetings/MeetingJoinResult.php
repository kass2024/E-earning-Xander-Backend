<?php

namespace App\Data\Meetings;

use App\Enums\MeetingProvider;

class MeetingJoinResult
{
    public function __construct(
        public readonly MeetingProvider $provider,
        public readonly string $joinUrl,
        public readonly string $role,
        public readonly ?string $token = null,
        public readonly ?string $externalMeetingId = null,
        public readonly array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider->value,
            'join_url' => $this->joinUrl,
            'token' => $this->token,
            'role' => $this->role,
            'external_meeting_id' => $this->externalMeetingId,
            'metadata' => $this->metadata ?: null,
        ], static fn ($v) => $v !== null);
    }
}
