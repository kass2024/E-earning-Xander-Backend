<?php

namespace App\Support;

use App\Models\MeetingRegistration;
use App\Models\WebinarSetting;
use Illuminate\Support\Facades\Schema;

class MeetingRegistrationJoinUrl
{
    public static function participantPath(
        string $meetingId,
        ?string $password = null,
        ?string $userName = null,
        ?string $userEmail = null,
    ): string {
        $raw = trim($meetingId);
        // Daily room names stay intact; Zoom IDs are digits-only.
        $isDailyRoom = (bool) preg_match('/^(webinar-|daily-|inst-|cohort-)/i', $raw)
            || (!preg_match('/^\d{9,15}$/', $raw) && preg_match('/[a-zA-Z_-]/', $raw));
        $meetingNumber = $isDailyRoom ? $raw : preg_replace('/\D+/', '', $raw);

        $params = [
            'meeting_number' => $meetingNumber,
            'role' => '0',
        ];

        if ($password && !$isDailyRoom) {
            $params['password'] = $password;
        }
        if ($userName) {
            $params['user_name'] = $userName;
        }
        if ($userEmail) {
            $params['user_email'] = $userEmail;
        }

        return '/meeting/room?' . http_build_query($params);
    }

    public static function participantUrl(
        string $meetingId,
        ?string $password = null,
        ?string $userName = null,
        ?string $userEmail = null,
    ): string {
        return FrontendUrl::base() . self::participantPath($meetingId, $password, $userName, $userEmail);
    }

    public static function forRegistration(MeetingRegistration $registration): ?string
    {
        $meetingId = trim((string) ($registration->zoom_meeting_id ?? ''));
        $settings = WebinarSetting::current();

        if ($meetingId === '') {
            $meetingId = trim((string) ($settings->zoom_meeting_id ?? ''));
        }

        if ($meetingId === '') {
            return null;
        }

        return self::participantUrl(
            $meetingId,
            self::resolvePassword($registration, $settings),
            $registration->full_name ?? null,
            $registration->email ?? null,
        );
    }

    private static function resolvePassword(MeetingRegistration $registration, ?WebinarSetting $settings = null): ?string
    {
        $settings ??= WebinarSetting::current();

        if (Schema::hasColumn('webinar_settings', 'zoom_password')) {
            $password = trim((string) ($settings->zoom_password ?? ''));
            if ($password !== '') {
                return $password;
            }
        }

        foreach ([$registration->zoom_join_url ?? null, $settings->zoom_join_url ?? null] as $joinUrl) {
            if (!is_string($joinUrl) || $joinUrl === '') {
                continue;
            }
            if (preg_match('/[?&]pwd=([^&]+)/i', $joinUrl, $matches)) {
                return urldecode($matches[1]);
            }
        }

        return null;
    }
}
