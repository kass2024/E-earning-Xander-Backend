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
    public static function meetingsForManagementPage(array $zoomMeetings, ?int $actorInstitutionId = null, bool $isMainAdmin = true): array
    {
        if (self::tableReady() && AdminZoomMeeting::query()->exists()) {
            return self::mergeWithZoomList($zoomMeetings, $actorInstitutionId, $isMainAdmin);
        }

        return self::excludePlatformMeetings($zoomMeetings);
    }

    /**
     * @param  list<array<string, mixed>>  $zoomMeetings
     * @return list<array<string, mixed>>
     */
    public static function mergeWithZoomList(array $zoomMeetings, ?int $actorInstitutionId = null, bool $isMainAdmin = true): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $query = AdminZoomMeeting::query()
            ->orderByDesc('start_time')
            ->orderByDesc('id');

        if (Schema::hasColumn('admin_zoom_meetings', 'platform_institution_id')) {
            if ($isMainAdmin) {
                // Hub operators see hub meetings (null institution) only.
                $query->whereNull('platform_institution_id');
            } elseif ($actorInstitutionId && $actorInstitutionId > 0) {
                $query->where('platform_institution_id', $actorInstitutionId);
            } else {
                return [];
            }
        }

        $records = $query->get();

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
            $base = $record->toMeetingArray();
            $fromZoom = $zoomById[$id] ?? null;
            if (is_array($fromZoom)) {
                $merged[] = array_merge($fromZoom, [
                    'meeting_mode' => $base['meeting_mode'] ?? ($fromZoom['meeting_mode'] ?? 'meeting'),
                    'provider' => $fromZoom['provider'] ?? ($base['provider'] ?? null),
                    'platform_institution_id' => $base['platform_institution_id'] ?? null,
                    'agenda' => $fromZoom['agenda'] ?? ($base['agenda'] ?? null),
                    'topic' => $fromZoom['topic'] ?? ($base['topic'] ?? null),
                ]);
            } else {
                $merged[] = $base;
            }
        }

        return $merged;
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

        $institutionId = null;
        if (isset($requestPayload['platform_institution_id']) && (int) $requestPayload['platform_institution_id'] > 0) {
            $institutionId = (int) $requestPayload['platform_institution_id'];
        } elseif ($createdByUserId) {
            $creator = \App\Models\User::query()->find($createdByUserId);
            if ($creator && !empty($creator->platform_institution_id)) {
                $institutionId = (int) $creator->platform_institution_id;
            }
        }

        $attrs = [
            'zoom_uuid' => $zoomResponse['uuid'] ?? null,
            'topic' => (string) ($zoomResponse['topic'] ?? $requestPayload['topic'] ?? 'Meeting'),
            'start_time' => $startTime,
            'duration' => isset($zoomResponse['duration'])
                ? (int) $zoomResponse['duration']
                : (isset($requestPayload['duration']) ? (int) $requestPayload['duration'] : null),
            'join_url' => $requestPayload['daily_room_url']
                ?? $zoomResponse['provider_join_url']
                ?? $zoomResponse['join_url']
                ?? null,
            'password' => $zoomResponse['password'] ?? ($requestPayload['password'] ?? null),
            'agenda' => $zoomResponse['agenda'] ?? ($requestPayload['agenda'] ?? null),
            'created_by_user_id' => $createdByUserId,
            'meta' => self::buildMeta($requestPayload),
            'meeting_provider' => (string) ($requestPayload['meeting_provider'] ?? $zoomResponse['provider'] ?? 'daily'),
            'meeting_mode' => (string) ($requestPayload['meeting_mode'] ?? $requestPayload['type'] ?? 'meeting'),
            'daily_room_name' => $requestPayload['daily_room_name']
                ?? ((($zoomResponse['provider'] ?? null) === 'daily') ? $meetingId : null),
            'daily_room_url' => $requestPayload['daily_room_url']
                ?? ((($zoomResponse['provider'] ?? null) === 'daily')
                    ? ($zoomResponse['provider_join_url'] ?? $zoomResponse['join_url'] ?? null)
                    : null),
            'session_status' => (string) ($zoomResponse['session_status'] ?? 'scheduled'),
        ];

        if (Schema::hasColumn('admin_zoom_meetings', 'platform_institution_id')) {
            $attrs['platform_institution_id'] = $institutionId;
        }

        return AdminZoomMeeting::query()->updateOrCreate(
            ['zoom_meeting_id' => $meetingId],
            $attrs
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
