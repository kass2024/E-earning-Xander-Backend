<?php

namespace App\Support;

use App\Models\AdminZoomMeeting;
use App\Models\LiveZoomCohort;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AdminZoomMeetingRegistry
{
    /**
     * @param  list<array<string, mixed>>  $zoomMeetings
     * @return list<array<string, mixed>>
     */
    public static function meetingsForManagementPage(array $zoomMeetings): array
    {
        if (self::tableReady() && AdminZoomMeeting::query()->exists()) {
            return self::mergeWithZoomList($zoomMeetings);
        }

        return self::excludePlatformMeetings($zoomMeetings);
    }

    /**
     * @param  array<string, mixed>  $zoomResponse
     * @param  array<string, mixed>  $requestPayload
     */
    public static function register(array $zoomResponse, ?int $createdByUserId = null, array $requestPayload = []): ?AdminZoomMeeting
    {
        if (!self::tableReady()) {
            return null;
        }

        $meetingId = trim((string) ($zoomResponse['id'] ?? ''));
        if ($meetingId === '') {
            return null;
        }

        $startTime = self::parseStartTime($zoomResponse['start_time'] ?? ($requestPayload['start_time'] ?? null));

        return AdminZoomMeeting::query()->updateOrCreate(
            ['zoom_meeting_id' => $meetingId],
            [
                'zoom_uuid' => $zoomResponse['uuid'] ?? null,
                'topic' => (string) ($zoomResponse['topic'] ?? $requestPayload['topic'] ?? 'Meeting'),
                'start_time' => $startTime,
                'duration' => isset($zoomResponse['duration'])
                    ? (int) $zoomResponse['duration']
                    : (isset($requestPayload['duration']) ? (int) $requestPayload['duration'] : null),
                'join_url' => $zoomResponse['join_url'] ?? null,
                'password' => $zoomResponse['password'] ?? ($requestPayload['password'] ?? null),
                'agenda' => $zoomResponse['agenda'] ?? ($requestPayload['agenda'] ?? null),
                'created_by_user_id' => $createdByUserId,
                'meta' => self::buildMeta($requestPayload),
            ]
        );
    }

    public static function unregister(string $meetingId): void
    {
        if (!self::tableReady()) {
            return;
        }

        $meetingId = trim($meetingId);
        if ($meetingId === '') {
            return;
        }

        AdminZoomMeeting::query()->where('zoom_meeting_id', $meetingId)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $zoomMeetings
     * @return list<array<string, mixed>>
     */
    public static function mergeWithZoomList(array $zoomMeetings): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $records = AdminZoomMeeting::query()
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->get();

        if ($records->isEmpty()) {
            return [];
        }

        $zoomById = [];
        foreach ($zoomMeetings as $meeting) {
            if (!is_array($meeting)) {
                continue;
            }
            $id = trim((string) ($meeting['id'] ?? ''));
            if ($id !== '') {
                $zoomById[$id] = $meeting;
            }
        }

        $merged = [];
        foreach ($records as $record) {
            $id = (string) $record->zoom_meeting_id;
            $merged[] = $zoomById[$id] ?? $record->toMeetingArray();
        }

        return $merged;
    }

    /**
     * Fallback when the registry table is empty: hide meetings owned by other platform menus.
     *
     * @param  list<array<string, mixed>>  $zoomMeetings
     * @return list<array<string, mixed>>
     */
    public static function excludePlatformMeetings(array $zoomMeetings): array
    {
        $excluded = self::excludedPlatformMeetingIds();

        return array_values(array_filter($zoomMeetings, function ($meeting) use ($excluded) {
            if (!is_array($meeting)) {
                return false;
            }

            $id = trim((string) ($meeting['id'] ?? ''));
            if ($id !== '' && isset($excluded[$id])) {
                return false;
            }

            return !self::looksLikePlatformManagedMeeting($meeting);
        }));
    }

    /**
     * @return array<string, true>
     */
    public static function excludedPlatformMeetingIds(): array
    {
        $excluded = [];

        foreach (AdminRecordingCatalog::trackedMeetingIds() as $meetingId) {
            $excluded[(string) $meetingId] = true;
        }

        if (Schema::hasTable('livezoom_cohort') && Schema::hasColumn('livezoom_cohort', 'zoom_meeting_id')) {
            LiveZoomCohort::query()
                ->whereNotNull('zoom_meeting_id')
                ->pluck('zoom_meeting_id')
                ->each(function ($meetingId) use (&$excluded) {
                    if ($meetingId) {
                        $excluded[(string) $meetingId] = true;
                    }
                });
        }

        return $excluded;
    }

    /**
     * @param  array<string, mixed>  $meeting
     */
    public static function looksLikePlatformManagedMeeting(array $meeting): bool
    {
        $topic = strtolower((string) ($meeting['topic'] ?? ''));

        $patterns = [
            'pathways webinar',
            'live zoom cohort',
            'live class',
            'zoom session',
            'information session',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($topic, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected static function tableReady(): bool
    {
        return Schema::hasTable('admin_zoom_meetings');
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    protected static function buildMeta(array $requestPayload): array
    {
        $meta = [];
        foreach (['category', 'type', 'recurrence', 'reminder', 'timezone', 'require_registration', 'invite_emails'] as $key) {
            if (array_key_exists($key, $requestPayload) && $requestPayload[$key] !== null && $requestPayload[$key] !== '') {
                $meta[$key] = $requestPayload[$key];
            }
        }

        return $meta;
    }

    protected static function parseStartTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
