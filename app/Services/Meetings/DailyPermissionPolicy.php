<?php

namespace App\Services\Meetings;

/**
 * Maps trusted application roles to Daily meeting-token permissions.
 *
 * @see https://docs.daily.co/reference/rest-api/meeting-tokens/create-meeting-token
 * @see https://docs.daily.co/docs/daily-js/guides/permissions
 */
class DailyPermissionPolicy
{
    public const ROLE_HOST = 'host';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_PANELIST = 'panelist';

    public const ROLE_PRESENTER = 'presenter';

    public const ROLE_ATTENDEE = 'attendee';

    public const MODE_MEETING = 'meeting';

    public const MODE_WEBINAR = 'webinar';

    /**
     * Resolve a trusted role from owner flag + optional context.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolveRole(bool $isOwner, array $context = []): string
    {
        $explicit = strtolower(trim((string) ($context['meeting_role'] ?? '')));
        $allowed = [
            self::ROLE_HOST,
            self::ROLE_MODERATOR,
            self::ROLE_PANELIST,
            self::ROLE_PRESENTER,
            self::ROLE_ATTENDEE,
        ];
        if (in_array($explicit, $allowed, true)) {
            return $explicit;
        }

        return $isOwner ? self::ROLE_HOST : self::ROLE_ATTENDEE;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function resolveMode(array $context = []): string
    {
        $mode = strtolower(trim((string) ($context['meeting_mode'] ?? self::MODE_MEETING)));

        return $mode === self::MODE_WEBINAR ? self::MODE_WEBINAR : self::MODE_MEETING;
    }

    /**
     * Build Daily token properties for permissions + start media state.
     *
     * @return array{
     *   is_owner: bool,
     *   start_audio_off: bool,
     *   start_video_off: bool,
     *   enable_screenshare: bool,
     *   permissions: array{hasPresence: bool, canSend: bool|list<string>, canAdmin: bool|list<string>}
     * }
     */
    public function tokenPermissionProps(string $role, string $mode = self::MODE_MEETING): array
    {
        return match ($role) {
            self::ROLE_HOST => [
                'is_owner' => true,
                'start_audio_off' => false,
                'start_video_off' => false,
                'enable_screenshare' => true,
                'permissions' => [
                    'hasPresence' => true,
                    'canSend' => true,
                    'canAdmin' => true,
                ],
            ],
            self::ROLE_MODERATOR => [
                'is_owner' => false,
                'start_audio_off' => false,
                'start_video_off' => false,
                'enable_screenshare' => true,
                'permissions' => [
                    'hasPresence' => true,
                    'canSend' => true,
                    'canAdmin' => ['participants'],
                ],
            ],
            self::ROLE_PANELIST, self::ROLE_PRESENTER => [
                'is_owner' => false,
                'start_audio_off' => false,
                'start_video_off' => false,
                'enable_screenshare' => true,
                'permissions' => [
                    'hasPresence' => true,
                    'canSend' => ['audio', 'video', 'screenVideo', 'screenAudio'],
                    'canAdmin' => false,
                ],
            ],
            default => $this->attendeeProps($mode),
        };
    }

    /**
     * @return array{
     *   is_owner: bool,
     *   start_audio_off: bool,
     *   start_video_off: bool,
     *   enable_screenshare: bool,
     *   permissions: array{hasPresence: bool, canSend: bool|list<string>, canAdmin: bool|list<string>}
     * }
     */
    protected function attendeeProps(string $mode): array
    {
        // Webinar audience: listen-only until host invites to stage.
        if ($mode === self::MODE_WEBINAR) {
            return [
                'is_owner' => false,
                'start_audio_off' => true,
                'start_video_off' => true,
                'enable_screenshare' => false,
                'permissions' => [
                    'hasPresence' => true,
                    'canSend' => false,
                    'canAdmin' => false,
                ],
            ];
        }

        // Meeting: join muted / camera off, but allowed to unmute and turn camera on.
        return [
            'is_owner' => false,
            'start_audio_off' => true,
            'start_video_off' => true,
            'enable_screenshare' => false,
            'permissions' => [
                'hasPresence' => true,
                'canSend' => ['audio', 'video'],
                'canAdmin' => false,
            ],
        ];
    }

    /**
     * Permissions payload for host updateParticipant after speaking approval.
     *
     * @return array{canSend: list<string>}
     */
    public function speakingGrantUpdate(bool $audio, bool $video, bool $screenShare = false): array
    {
        $canSend = [];
        if ($audio) {
            $canSend[] = 'audio';
        }
        if ($video) {
            $canSend[] = 'video';
        }
        if ($screenShare) {
            $canSend[] = 'screenVideo';
            $canSend[] = 'screenAudio';
        }

        return ['canSend' => $canSend];
    }

    /**
     * @return array{canSend: bool}
     */
    public function revokePublishUpdate(): array
    {
        return ['canSend' => false];
    }

    public function canModerate(string $role): bool
    {
        return in_array($role, [self::ROLE_HOST, self::ROLE_MODERATOR], true);
    }
}
