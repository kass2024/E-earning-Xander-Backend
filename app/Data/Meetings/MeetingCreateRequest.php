<?php

namespace App\Data\Meetings;

use Carbon\Carbon;

class MeetingCreateRequest
{
    public function __construct(
        public readonly string $topic,
        public readonly Carbon $startAt,
        public readonly int $durationMinutes,
        public readonly string $timezone,
        public readonly ?int $platformInstitutionId = null,
        public readonly ?int $actorUserId = null,
        public readonly ?string $actorEmail = null,
        public readonly ?string $agenda = null,
        public readonly bool $joinBeforeHost = false,
        public readonly bool $waitingRoom = true,
        public readonly bool $muteUponEntry = true,
        public readonly bool $autoRecording = false,
        public readonly ?int $courseId = null,
        public readonly ?int $materialId = null,
        public readonly array $context = [],
    ) {}
}
