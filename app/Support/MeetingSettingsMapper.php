<?php

namespace App\Support;

use App\Services\Meetings\DailyPermissionPolicy;

/**
 * Normalizes meeting UI toggles and maps them to Daily room + token behavior.
 */
class MeetingSettingsMapper
{
    /** @var list<string> */
    public const META_KEYS = [
        'join_before_host',
        'mute_upon_entry',
        'auto_recording',
        'host_video',
        'participant_video',
        'waiting_room',
        'meeting_authentication',
        'registrants_email_notification',
        'allow_multiple_devices',
        'audio',
        'require_registration',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool|string>
     */
    public static function normalize(array $data): array
    {
        $joinBeforeHost = self::toBool($data['join_before_host'] ?? false);
        $waitingRoom = array_key_exists('waiting_room', $data)
            ? self::toBool($data['waiting_room'])
            : !$joinBeforeHost;

        return [
            'join_before_host' => $joinBeforeHost,
            'mute_upon_entry' => self::toBool($data['mute_upon_entry'] ?? false),
            'auto_recording' => self::toBool($data['auto_recording'] ?? false),
            'host_video' => self::toBool($data['host_video'] ?? true),
            'participant_video' => self::toBool($data['participant_video'] ?? false),
            'waiting_room' => $waitingRoom,
            'meeting_authentication' => self::toBool($data['meeting_authentication'] ?? false),
            'registrants_email_notification' => self::toBool($data['registrants_email_notification'] ?? true),
            'allow_multiple_devices' => self::toBool($data['allow_multiple_devices'] ?? false),
            'audio' => self::normalizeAudio($data['audio'] ?? 'both'),
            'require_registration' => self::toBool($data['require_registration'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, bool|string>
     */
    public static function fromMeta(?array $meta): array
    {
        return self::normalize(is_array($meta) ? $meta : []);
    }

    /**
     * @param  array<string, bool|string>  $settings
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function dailyRoomProperties(array $settings, array $overrides = []): array
    {
        $props = [
            'start_audio_off' => (bool) $settings['mute_upon_entry'],
            'start_video_off' => !(bool) $settings['participant_video'],
        ];

        if (!empty($settings['auto_recording'])) {
            $props['enable_recording'] = 'cloud';
        }

        // Waiting room → knock before owner admits; join-before-host off is enforced in join auth.
        if (!empty($settings['waiting_room'])) {
            $props['enable_knocking'] = true;
        }

        return array_merge($props, $overrides);
    }

    /**
     * Apply host/participant media toggles to Daily token permission props.
     *
     * @param  array<string, mixed>  $props
     * @param  array<string, bool|string>  $settings
     * @return array<string, mixed>
     */
    public static function applyToTokenProps(array $props, array $settings, string $role): array
    {
        if ($role === DailyPermissionPolicy::ROLE_HOST) {
            $props['start_video_off'] = !(bool) $settings['host_video'];
            $props['start_audio_off'] = false;

            return $props;
        }

        if (!in_array($role, [DailyPermissionPolicy::ROLE_ATTENDEE], true)) {
            return $props;
        }

        $props['start_audio_off'] = (bool) $settings['mute_upon_entry'];
        $props['start_video_off'] = !(bool) $settings['participant_video'];

        $canSend = $props['permissions']['canSend'] ?? false;
        if (is_array($canSend) && !(bool) $settings['participant_video']) {
            $props['permissions']['canSend'] = array_values(array_filter(
                $canSend,
                static fn (string $media): bool => $media !== 'video',
            ));
            foreach (['screenVideo', 'screenAudio'] as $screenMedia) {
                if (!in_array($screenMedia, $props['permissions']['canSend'], true)) {
                    $props['permissions']['canSend'][] = $screenMedia;
                }
            }
        }

        return $props;
    }

    /**
     * Defaults for Pathways / registration webinars on Daily.
     *
     * @return array<string, bool|string>
     */
    public static function webinarRegistrationDefaults(bool $recordingEnabled = false): array
    {
        return self::normalize([
            'join_before_host' => true,
            'waiting_room' => true,
            'mute_upon_entry' => true,
            'auto_recording' => $recordingEnabled,
            'host_video' => true,
            'participant_video' => false,
        ]);
    }

    /**
     * Extract persisted settings keys for admin_zoom_meetings.meta.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function metaFromPayload(array $payload): array
    {
        $normalized = self::normalize($payload);
        $meta = [];
        foreach (self::META_KEYS as $key) {
            $meta[$key] = $normalized[$key];
        }

        return $meta;
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    public static function normalizeAudio(mixed $value): string
    {
        $audio = strtolower(trim((string) $value));

        return in_array($audio, ['both', 'voip', 'telephony'], true) ? $audio : 'both';
    }
}
