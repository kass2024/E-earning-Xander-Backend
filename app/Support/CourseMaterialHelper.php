<?php



namespace App\Support;



use App\Models\CourseMaterial;
use App\Models\PlatformInstitution;
use App\Enums\MeetingProvider;
use App\Services\ZoomService;
use App\Support\FrontendUrl;
use Carbon\Carbon;



class CourseMaterialHelper

{

    public static function materialKind(CourseMaterial $material): string

    {

        $type = strtolower((string) ($material->type ?? ''));

        $url = strtolower((string) ($material->resource_url ?? ''));



        if ($type === 'zoom') {

            return 'zoom';

        }

        if (in_array($type, ['quiz', 'assessment'], true)) {

            return $type;

        }

        if (in_array($type, ['video', 'document', 'pdf'], true)) {

            return $type === 'pdf' ? 'document' : $type;

        }

        if (in_array($type, ['image', 'audio'], true)) {

            return $type;

        }

        if (str_contains($url, '.mp4') || str_contains($url, 'video') || str_contains($url, 'youtube') || str_contains($url, 'vimeo')) {

            return 'video';

        }

        if (str_contains($url, '.pdf') || $type === 'file') {

            return 'document';

        }



        return $type ?: 'resource';

    }



    public static function learnerJoinUrl(CourseMaterial $material): ?string

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];

        if (!empty($meta['join_url']) && is_string($meta['join_url'])) {

            return $meta['join_url'];

        }



        $url = (string) ($material->resource_url ?? '');

        if ($url === '') {

            return null;

        }



        // Participant join links contain /j/; host start links contain /s/

        if (str_contains($url, '/j/')) {

            return $url;

        }



        return null;

    }



    public static function meetingId(CourseMaterial $material): ?string

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $meetingId = $meta['meeting_id'] ?? null;



        return $meetingId ? (string) $meetingId : null;

    }

    public static function meetingProvider(CourseMaterial $material, ?PlatformInstitution $institution = null): MeetingProvider
    {
        $meta = is_array($material->metadata) ? $material->metadata : [];
        $stored = $meta['meeting_provider'] ?? null;
        if (is_string($stored) && trim($stored) !== '') {
            return MeetingProvider::fromStringOrDefault($stored);
        }

        // Prefer Daily when room metadata clearly points at Daily.
        if (!empty($meta['daily_room_name']) || !empty($meta['daily_room_url'])) {
            return MeetingProvider::Daily;
        }
        $joinUrl = (string) ($meta['join_url'] ?? $material->resource_url ?? '');
        if ($joinUrl !== '' && str_contains($joinUrl, '.daily.co/')) {
            return MeetingProvider::Daily;
        }

        // Existing Zoom-style meeting without stored provider = legacy Zoom.
        if (self::hasExistingMeeting($material)) {
            return MeetingProvider::Zoom;
        }

        // New meeting: institution or main platform default (Daily).
        if ($institution) {
            return MeetingProvider::fromStringOrDefault($institution->meeting_provider);
        }

        return app(\App\Services\PlatformSettingsService::class)->mainPlatformMeetingProvider();
    }

    public static function hasExistingMeeting(CourseMaterial $material): bool
    {
        $meta = is_array($material->metadata) ? $material->metadata : [];

        return !empty($meta['meeting_id'])
            || !empty($meta['daily_room_name'])
            || !empty($meta['join_url']);
    }

    public static function isDailyMeeting(CourseMaterial $material): bool
    {
        return self::meetingProvider($material) === MeetingProvider::Daily;
    }

    public static function externalMeetingReference(CourseMaterial $material): ?string
    {
        $meta = is_array($material->metadata) ? $material->metadata : [];

        if (self::isDailyMeeting($material)) {
            $dailyRoom = $meta['daily_room_name'] ?? $meta['meeting_id'] ?? null;

            return $dailyRoom ? (string) $dailyRoom : null;
        }

        return self::meetingId($material);
    }



    public static function meetingPassword(CourseMaterial $material): ?string
    {
        $meta = is_array($material->metadata) ? $material->metadata : [];

        if (!empty($meta['password']) && is_string($meta['password'])) {
            return (string) $meta['password'];
        }

        return null;
    }

    public static function embedRoomPath(CourseMaterial $material, int $role = 0, ?int $studentId = null): ?string
    {
        if (!self::meetingId($material)) {
            return null;
        }

        $params = [
            'material_id' => $material->id,
            'role' => $role,
        ];

        if ($studentId !== null && $studentId > 0) {
            $params['student_id'] = $studentId;
        }

        return '/meeting/room?' . http_build_query($params);
    }

    public static function embedRoomUrl(CourseMaterial $material, int $role = 0, ?int $studentId = null): ?string
    {
        $path = self::embedRoomPath($material, $role, $studentId);

        return $path ? rtrim(FrontendUrl::base(), '/') . $path : null;
    }

    public static function learnerPortalUrl(): string
    {
        return rtrim(FrontendUrl::base(), '/') . '/dashboard/learner/live-classes';
    }

    public static function instructorClassesUrl(): string
    {
        return rtrim(FrontendUrl::base(), '/') . '/dashboard/classes';
    }

    public static function scheduledAt(CourseMaterial $material): ?Carbon

    {

        if ($material->scheduled_at) {

            return Carbon::parse($material->scheduled_at);

        }



        $title = (string) ($material->title ?? '');

        if (preg_match('/(?:Zoom session|Live class)\s*-\s*(.+)$/i', $title, $matches)) {

            try {

                return Carbon::parse(trim($matches[1]));

            } catch (\Throwable) {

                return null;

            }

        }



        return null;

    }



    public static function durationMinutes(CourseMaterial $material): int

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $duration = (int) ($meta['duration'] ?? 60);



        return $duration > 0 ? min($duration, 480) : 60;

    }



    /**

     * @return array{

     *   session_status: 'live'|'upcoming'|'ended'|'unknown',

     *   can_join: bool,

     *   is_past: bool,

     *   is_upcoming: bool,

     *   is_live_now: bool,

     *   duration_minutes: int,

     *   zoom_is_live?: bool

     * }

     */

    public static function liveSessionState(CourseMaterial $material, ?array $liveMeetingIds = null): array

    {

        $scheduled = self::scheduledAt($material);

        $durationMinutes = self::durationMinutes($material);

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $meetingId = self::meetingId($material);



        if (!$scheduled) {

            return [

                'session_status' => 'unknown',

                'can_join' => false,

                'is_past' => false,

                'is_upcoming' => false,

                'is_live_now' => false,

                'duration_minutes' => $durationMinutes,

            ];

        }



        $now = now();

        // Future start times stay upcoming even if Zoom lists the meeting id elsewhere.
        if ($now->lt($scheduled)) {
            return [
                'session_status' => 'upcoming',
                'can_join' => false,
                'is_past' => false,
                'is_upcoming' => true,
                'is_live_now' => false,
                'duration_minutes' => $durationMinutes,
            ];
        }

        $scheduledEnd = $scheduled->copy()->addMinutes($durationMinutes);

        $sessionStartedAt = null;



        if (!empty($meta['session_started_at'])) {

            try {

                $sessionStartedAt = Carbon::parse($meta['session_started_at']);

            } catch (\Throwable) {

                $sessionStartedAt = null;

            }

        }



        $zoomLive = false;

        if ($meetingId && self::meetingProvider($material) === MeetingProvider::Zoom) {

            if ($liveMeetingIds !== null) {

                $zoomLive = in_array($meetingId, array_map('strval', $liveMeetingIds), true);

            } else {

                $zoomLive = app(ZoomService::class)->isMeetingLive($meetingId);

            }

            if ($zoomLive && $sessionStartedAt === null) {
                self::markSessionStarted($material);
                $sessionStartedAt = now();
            }

        }

        if (self::meetingProvider($material) === MeetingProvider::Daily) {
            if (!empty($meta['provider_started_at']) || !empty($meta['session_started_at'])) {
                if ($sessionStartedAt === null) {
                    try {
                        $sessionStartedAt = Carbon::parse($meta['session_started_at'] ?? $meta['provider_started_at']);
                    } catch (\Throwable) {
                        $sessionStartedAt = null;
                    }
                }
            }

            if (!empty($meta['provider_ended_at'])) {
                return [
                    'session_status' => 'ended',
                    'can_join' => false,
                    'is_past' => true,
                    'is_upcoming' => false,
                    'is_live_now' => false,
                    'duration_minutes' => $durationMinutes,
                    'zoom_is_live' => false,
                ];
            }
        }



        $sessionEnd = $sessionStartedAt

            ? $sessionStartedAt->copy()->addMinutes($durationMinutes)

            : $scheduledEnd;



        if ($meetingId) {

            if (self::meetingProvider($material) === MeetingProvider::Daily) {
                $canJoin = $now->gte($scheduled) && $now->lte($scheduledEnd)
                    && empty($meta['provider_ended_at']);
                $isPast = !empty($meta['provider_ended_at']) || $now->gt($scheduledEnd);
            } else {
                // Zoom-backed sessions: join when the host has started (live on Zoom or marked started), not only at scheduled time.
                $canJoin = $zoomLive || ($sessionStartedAt !== null && $now->lte($sessionEnd));
                $isPast = !$canJoin && ($now->gt($scheduledEnd) || ($sessionStartedAt !== null && $now->gt($sessionEnd)));
            }

        } else {

            // Fallback for legacy rows without a stored meeting id.

            $canJoin = $now->gte($scheduled) && $now->lte($scheduledEnd);

            $isPast = $now->gt($scheduledEnd);

        }



        $isLiveNow = $canJoin;

        $isUpcoming = !$isPast && !$canJoin;



        return [

            'session_status' => $isPast ? 'ended' : ($isLiveNow ? 'live' : 'upcoming'),

            'can_join' => $isLiveNow,

            'is_past' => $isPast,

            'is_upcoming' => $isUpcoming,

            'is_live_now' => $isLiveNow,

            'duration_minutes' => $durationMinutes,

            'zoom_is_live' => $zoomLive,

        ];

    }



    public static function toLiveClassArray(CourseMaterial $material, ?array $liveMeetingIds = null): array

    {

        $scheduled = self::scheduledAt($material);

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $state = self::liveSessionState($material, $liveMeetingIds);



        return array_merge([

            'id' => $material->id,

            'course_id' => $material->course_id,

            'title' => $material->title,

            'course_title' => $material->course?->title,

            'meeting_id' => self::meetingId($material),

            'join_url' => null,

            'embed_room_path' => self::embedRoomPath($material, 0),

            'host_room_path' => self::embedRoomPath($material, 1),

            'share_path' => self::embedRoomPath($material, 0),

            'share_url' => self::embedRoomUrl($material, 0),

            'start_time' => $scheduled?->toIso8601String(),

            'scheduled_at' => $scheduled?->toIso8601String(),

            'description' => $material->description,

            'timezone' => $meta['timezone'] ?? null,

            'type' => 'live_class',

        ], $state);

    }



    public static function toLearnerArray(CourseMaterial $material, ?array $liveMeetingIds = null): array

    {

        $kind = self::materialKind($material);

        $scheduled = self::scheduledAt($material);

        $state = $kind === 'zoom' ? self::liveSessionState($material, $liveMeetingIds) : [];

        $meta = is_array($material->metadata) ? $material->metadata : [];

        $fileExtras = [];
        if (\App\Support\MaterialFileHelper::isPCloudMaterial($meta)) {
            $filename = (string) ($meta['filename'] ?? $material->title ?? 'file');
            $fileExtras = [
                'storage' => 'pcloud',
                'pcloud_file_id' => (int) $meta['pcloud_file_id'],
                'file_category' => $meta['category'] ?? \App\Support\MaterialFileHelper::categoryFromFilename($filename),
                'file_size' => isset($meta['size']) ? (int) $meta['size'] : null,
                'filename' => $filename,
            ];
        }

        if (in_array($kind, ['quiz', 'assessment'], true)) {
            $fileExtras['topic'] = $meta['topic'] ?? null;
            $fileExtras['question_count'] = count($meta['questions'] ?? []);
            $fileExtras['assessment_kind'] = $meta['assessment_kind'] ?? 'quiz';
            $fileExtras['availability_mode'] = \App\Support\QuizMaterialHelper::availabilityMode($material);
            $fileExtras['is_quiz_open'] = \App\Support\QuizMaterialHelper::isOpenForAccess($material);
            $fileExtras['has_interactive_quiz'] = count($meta['questions'] ?? []) > 0
                && (!array_key_exists('status', $meta) || ($meta['status'] ?? '') === 'published')
                && $fileExtras['is_quiz_open'];
            $fileExtras['passing_score'] = (int) ($meta['passing_score'] ?? 70);
            $fileExtras['time_limit_minutes'] = !empty($meta['time_limit_minutes']) ? (int) $meta['time_limit_minutes'] : null;
            $fileExtras['quiz_status'] = $meta['status'] ?? 'draft';
        }



        return array_merge([

            'id' => $material->id,

            'course_id' => $material->course_id,

            'title' => $material->title,

            'description' => $material->description,

            'type' => $material->type,

            'kind' => $kind,

            'resource_url' => $kind === 'zoom' ? null : $material->resource_url,

            'join_url' => null,

            'meeting_id' => $kind === 'zoom' ? self::meetingId($material) : null,

            'embed_room_path' => $kind === 'zoom' ? self::embedRoomPath($material, 0) : null,

            'host_room_path' => $kind === 'zoom' ? self::embedRoomPath($material, 1) : null,

            'scheduled_at' => $scheduled?->toIso8601String(),

            'duration_minutes' => $kind === 'zoom' ? self::durationMinutes($material) : null,

            'sort_order' => $material->sort_order,

            'created_at' => $material->created_at?->toIso8601String(),

        ], $state, $fileExtras);

    }



    public static function markSessionStarted(CourseMaterial $material): CourseMaterial

    {

        $meta = is_array($material->metadata) ? $material->metadata : [];



        if (empty($meta['session_started_at'])) {

            $meta['session_started_at'] = now()->toIso8601String();

            $material->metadata = $meta;

            $material->save();

        }



        return $material;

    }

}

