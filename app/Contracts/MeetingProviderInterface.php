<?php

namespace App\Contracts;

use App\Data\Meetings\MeetingCreateRequest;
use App\Data\Meetings\MeetingCreateResult;
use App\Data\Meetings\MeetingJoinRequest;
use App\Data\Meetings\MeetingJoinResult;
use App\Enums\MeetingProvider;

interface MeetingProviderInterface
{
    public function provider(): MeetingProvider;

    public function isConfigured(): bool;

    public function createMeeting(MeetingCreateRequest $request): MeetingCreateResult;

    public function deleteMeeting(string $externalMeetingId, array $context = []): bool;

    public function buildJoinDetails(MeetingJoinRequest $request): MeetingJoinResult;

    public function supportsRecording(): bool;

    public function startRecording(string $externalMeetingId, array $context = []): bool;

    public function stopRecording(string $externalMeetingId, array $context = []): bool;
}
