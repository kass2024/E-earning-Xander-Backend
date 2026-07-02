<?php

namespace App\Services;

use App\Models\LiveZoomCohort;
use App\Support\LiveZoomCohortHelper;
use Illuminate\Support\Facades\Schema;

class LiveZoomCohortZoomService
{
    /** @var array<int, string> */
    protected array $dayNames = [
        'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
    ];

    public function __construct(protected ZoomService $zoom)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureZoomMeeting(LiveZoomCohort $cohort, bool $forceNew = false): array
    {
        if (!$this->zoom->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Zoom API credentials are missing. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, and ZOOM_CLIENT_SECRET on the server.',
            ];
        }

        if (
            !$forceNew
            && trim((string) ($cohort->zoom_link ?? '')) !== ''
            && trim((string) ($cohort->zoom_meeting_id ?? '')) !== ''
            && ($cohort->session_status ?? 'idle') === 'live'
        ) {
            $existingId = $this->normalizeMeetingId($cohort);
            $check = $existingId !== '' ? $this->zoom->getMeeting($existingId) : null;
            if ($check && empty($check['error']) && $this->zoom->isMeetingJoinableForEmbed($check)) {
                $this->syncMeetingCredentials($cohort, $check);

                return [
                    'ok' => true,
                    'reused' => true,
                    'message' => 'Using the active Zoom meeting for this cohort.',
                    'zoom' => $this->formatZoomPayload($cohort->fresh()),
                ];
            }

            if ($existingId !== '') {
                return [
                    'ok' => true,
                    'reused' => true,
                    'message' => 'Using stored Zoom meeting credentials for this live session.',
                    'zoom' => $this->formatZoomPayload($cohort->fresh()),
                ];
            }
        }

        $dayLabel = $this->dayNames[(int) $cohort->day_of_week] ?? 'Cohort';
        $topic = trim((string) ($cohort->notes ?? ''));
        if ($topic === '') {
            $topic = "Live Zoom Cohort — {$dayLabel}";
        }

        $duration = $this->durationMinutes($cohort);
        $timezone = (string) ($cohort->timezone ?: 'Africa/Kigali');
        $agenda = $this->buildAgenda($cohort, $dayLabel, $duration, $timezone);

        $meeting = $this->zoom->createPersistentCohortMeeting([
            'topic' => $topic,
            'duration' => $duration,
            'agenda' => $agenda,
            'timezone' => $timezone,
            'join_before_host' => true,
            'waiting_room' => false,
            'mute_upon_entry' => false,
            'auto_recording' => false,
        ], $this->zoom->resolveHostUserId());

        if ($meeting === null) {
            return [
                'ok' => false,
                'message' => 'Unable to contact Zoom to create the cohort meeting.',
            ];
        }

        if (!empty($meeting['error'])) {
            return [
                'ok' => false,
                'message' => data_get($meeting, 'body.message', 'Zoom rejected meeting creation.'),
                'details' => $meeting['body'] ?? null,
            ];
        }

        $meetingId = isset($meeting['id']) ? (string) $meeting['id'] : null;
        $joinUrl = $meeting['join_url'] ?? null;
        $startUrl = $meeting['start_url'] ?? null;
        $password = $meeting['password'] ?? null;

        if (!$meetingId || !$joinUrl) {
            return [
                'ok' => false,
                'message' => 'Zoom created a meeting but did not return join links.',
            ];
        }

        $description = $this->buildShareDescription($cohort, $dayLabel, $topic, $meetingId, $joinUrl, $password, $duration, $timezone);

        $cohort->zoom_meeting_id = $meetingId;
        $cohort->zoom_link = $joinUrl;
        if (Schema::hasColumn('livezoom_cohort', 'zoom_start_url')) {
            $cohort->zoom_start_url = $startUrl;
        }
        if (Schema::hasColumn('livezoom_cohort', 'zoom_password')) {
            $cohort->zoom_password = $password;
        }
        if (Schema::hasColumn('livezoom_cohort', 'zoom_description')) {
            $cohort->zoom_description = $description;
        }
        $cohort->save();

        $this->ensureEmbedFriendlyMeetingSettings($meetingId);

        return [
            'ok' => true,
            'reused' => false,
            'message' => 'Zoom meeting created automatically.',
            'zoom' => $this->formatZoomPayload($cohort->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatZoomPayload(LiveZoomCohort $cohort): array
    {
        $dayLabel = $this->dayNames[(int) $cohort->day_of_week] ?? 'Cohort';

        $embedEnabled = app(ZoomMeetingSdkService::class)->isConfigured();

        return [
            'topic' => trim((string) ($cohort->notes ?? '')) ?: "Live Zoom Cohort — {$dayLabel}",
            'meeting_id' => $cohort->zoom_meeting_id ?? null,
            'join_url' => $cohort->zoom_link ?? null,
            'start_url' => $cohort->zoom_start_url ?? null,
            'password' => $cohort->zoom_password ?? null,
            'description' => $cohort->zoom_description ?? null,
            'share_text' => $cohort->zoom_description ?? null,
            'public_join_url' => LiveZoomCohortHelper::publicJoinUrl($cohort),
            'embed_enabled' => $embedEnabled,
            'host_studio_url' => LiveZoomCohortHelper::hostStudioUrl($cohort),
            'participant_room_path' => LiveZoomCohortHelper::participantRoomPath($cohort),
            'host_studio_path' => LiveZoomCohortHelper::hostStudioPath($cohort),
            'schedule' => [
                'day' => $dayLabel,
                'start_time' => $cohort->start_time,
                'end_time' => $cohort->end_time,
                'timezone' => $cohort->timezone,
            ],
        ];
    }

    protected function durationMinutes(LiveZoomCohort $cohort): int
    {
        try {
            $start = \Carbon\Carbon::createFromFormat('H:i:s', substr((string) $cohort->start_time, 0, 8));
            $end = \Carbon\Carbon::createFromFormat('H:i:s', substr((string) $cohort->end_time, 0, 8));
            $minutes = $start->diffInMinutes($end);

            return max(15, min(240, $minutes > 0 ? $minutes : 60));
        } catch (\Throwable) {
            return 60;
        }
    }

    protected function buildAgenda(LiveZoomCohort $cohort, string $dayLabel, int $duration, string $timezone): string
    {
        $lines = [
            (string) config('app.name', 'parrotglobalstudyacademy') . ' — Live Zoom Cohort',
            "Day: {$dayLabel}",
            'Time: ' . substr((string) $cohort->start_time, 0, 5) . ' – ' . substr((string) $cohort->end_time, 0, 5) . " ({$timezone})",
            "Duration: {$duration} minutes",
            'Joiners enter a queue — one participant at a time.',
        ];

        if (trim((string) ($cohort->notes ?? '')) !== '') {
            $lines[] = 'Notes: ' . trim((string) $cohort->notes);
        }

        return implode("\n", $lines);
    }

    protected function buildShareDescription(
        LiveZoomCohort $cohort,
        string $dayLabel,
        string $topic,
        string $meetingId,
        string $joinUrl,
        ?string $password,
        int $duration,
        string $timezone
    ): string {
        $lines = [
            (string) config('app.name', 'parrotglobalstudyacademy') . ' — Live Zoom Cohort',
            "Topic: {$topic}",
            "Meeting ID: {$meetingId}",
            "Join link: {$joinUrl}",
        ];

        if ($password) {
            $lines[] = "Passcode: {$password}";
        }

        $lines[] = "Schedule: {$dayLabel}, " . substr((string) $cohort->start_time, 0, 5) . ' – ' . substr((string) $cohort->end_time, 0, 5) . " ({$timezone})";
        $lines[] = "Duration: {$duration} minutes";
        $lines[] = 'Public join page (no account required): ' . LiveZoomCohortHelper::publicJoinUrl($cohort);
        $lines[] = 'Please join through the queue when the session is live.';

        return implode("\n", $lines);
    }

    /**
     * Prepare stored cohort credentials for Meeting SDK auth.
     * Does not create or rotate meetings — REST startSession/ensureZoomMeeting owns that.
     *
     * @return array{0: LiveZoomCohort, 1: array<string, mixed>|null}
     */
    public function resolveCohortForSdkAuth(LiveZoomCohort $cohort): array
    {
        $meetingId = $this->normalizeMeetingId($cohort);
        if ($meetingId === '' || trim((string) ($cohort->zoom_link ?? '')) === '') {
            throw new \RuntimeException('Start the cohort session first to create a Zoom meeting.');
        }

        $meetingDetails = $this->maybeSyncMeetingFromApi($cohort);

        return [$cohort->fresh(), $meetingDetails];
    }

    /**
     * Optional REST sync when read API is available. Never fails the SDK path.
     *
     * @return array<string, mixed>|null
     */
    public function maybeSyncMeetingFromApi(LiveZoomCohort $cohort): ?array
    {
        $meetingId = $this->normalizeMeetingId($cohort);
        if ($meetingId === '') {
            return null;
        }

        $details = $this->zoom->getMeeting($meetingId);
        if (!$details || !empty($details['error'])) {
            return null;
        }

        $this->syncMeetingCredentials($cohort, $details);

        return $details;
    }

    /**
     * @deprecated SDK auth uses resolveCohortForSdkAuth(). Kept for explicit refresh flows.
     */
    public function ensureActiveMeetingForSdk(LiveZoomCohort $cohort): LiveZoomCohort
    {
        [$cohort] = $this->resolveCohortForSdkAuth($cohort);

        return $cohort;
    }

    /**
     * Force a fresh persistent meeting (explicit host "Refresh Zoom meeting" only).
     */
    public function refreshMeetingForSdk(LiveZoomCohort $cohort): LiveZoomCohort
    {
        $result = $this->ensureZoomMeeting($cohort, true);
        if (empty($result['ok'])) {
            throw new \RuntimeException($result['message'] ?? 'Unable to refresh the Zoom meeting for this cohort.');
        }

        return $cohort->fresh();
    }

    protected function normalizeMeetingId(LiveZoomCohort $cohort): string
    {
        $fromColumn = preg_replace('/\D+/', '', (string) ($cohort->zoom_meeting_id ?? '')) ?: '';
        if ($fromColumn !== '') {
            return $fromColumn;
        }

        return (string) ($this->zoom->extractMeetingIdFromJoinUrl($cohort->zoom_link ?? null) ?? '');
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function syncMeetingCredentials(LiveZoomCohort $cohort, array $details): void
    {
        $meetingId = isset($details['id']) ? (string) $details['id'] : $this->normalizeMeetingId($cohort);
        $password = $details['password'] ?? $details['passcode'] ?? null;
        $joinUrl = $details['join_url'] ?? $cohort->zoom_link;
        $startUrl = $details['start_url'] ?? $cohort->zoom_start_url;

        if ($meetingId !== '') {
            $cohort->zoom_meeting_id = $meetingId;
        }
        if ($joinUrl) {
            $cohort->zoom_link = $joinUrl;
        }
        if (Schema::hasColumn('livezoom_cohort', 'zoom_start_url') && $startUrl) {
            $cohort->zoom_start_url = $startUrl;
        }
        if (Schema::hasColumn('livezoom_cohort', 'zoom_password')) {
            if ($password) {
                $cohort->zoom_password = (string) $password;
            } elseif (trim((string) ($cohort->zoom_password ?? '')) === '' && $joinUrl) {
                $fromUrl = $this->zoom->extractPasswordFromJoinUrl($joinUrl);
                if ($fromUrl) {
                    $cohort->zoom_password = $fromUrl;
                }
            }
        }

        $cohort->save();
    }

    protected function ensureEmbedFriendlyMeetingSettings(string $meetingId): void
    {
        $this->zoom->updateMeetingSettings($meetingId, [
            'join_before_host' => true,
            'waiting_room' => false,
            'mute_upon_entry' => false,
        ]);
    }
}
