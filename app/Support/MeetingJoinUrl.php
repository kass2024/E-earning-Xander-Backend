<?php

namespace App\Support;

/**
 * In-app meeting join URLs for invites and copy/share.
 * Never email raw Daily/Zoom provider URLs — rooms are private and require our token flow.
 */
class MeetingJoinUrl
{
    public static function participantPath(string $meetingNumber, ?string $userEmail = null, ?string $userName = null): string
    {
        $params = [
            'meeting_number' => $meetingNumber,
            'role' => '0',
        ];
        if ($userEmail !== null && trim($userEmail) !== '') {
            $params['user_email'] = trim($userEmail);
        }
        if ($userName !== null && trim($userName) !== '') {
            $params['user_name'] = trim($userName);
        }

        return '/meeting/room?' . http_build_query($params);
    }

    public static function participantUrl(string $meetingNumber, ?string $userEmail = null, ?string $userName = null): string
    {
        return rtrim(FrontendUrl::base(), '/') . self::participantPath($meetingNumber, $userEmail, $userName);
    }

    public static function hostPath(string $meetingNumber): string
    {
        return '/meeting/room?' . http_build_query([
            'meeting_number' => $meetingNumber,
            'role' => '1',
        ]);
    }

    public static function hostUrl(string $meetingNumber): string
    {
        return rtrim(FrontendUrl::base(), '/') . self::hostPath($meetingNumber);
    }

    public static function isProviderUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        return str_contains(strtolower($url), 'daily.co') || str_contains(strtolower($url), 'zoom.us');
    }

    /**
     * Prefer an app-domain join URL; rewrite known provider URLs to our embed path when room id is known.
     */
    public static function preferAppJoinUrl(?string $url, ?string $meetingNumber = null, int $role = 0): ?string
    {
        $url = trim((string) $url);
        $meetingNumber = trim((string) $meetingNumber);

        if ($meetingNumber !== '' && ($url === '' || self::isProviderUrl($url))) {
            return $role === 1
                ? self::hostUrl($meetingNumber)
                : self::participantUrl($meetingNumber);
        }

        if ($url === '' || self::isProviderUrl($url)) {
            return null;
        }

        return $url;
    }
}
