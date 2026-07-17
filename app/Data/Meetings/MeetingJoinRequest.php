<?php

namespace App\Data\Meetings;

class MeetingJoinRequest
{
    /**
     * @param  array<string, mixed>  $context  Optional: meeting_role, meeting_mode
     */
    public function __construct(
        public readonly string $externalMeetingId,
        public readonly string $roomUrl,
        public readonly string $userName,
        public readonly string $userId,
        public readonly bool $isOwner,
        public readonly ?int $platformInstitutionId = null,
        public readonly ?\DateTimeInterface $expiresAt = null,
        public readonly array $context = [],
    ) {}
}
