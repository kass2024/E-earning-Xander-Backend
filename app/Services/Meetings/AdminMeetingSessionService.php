<?php

namespace App\Services\Meetings;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks host presence for admin Daily meetings (join-before-host / waiting room).
 */
class AdminMeetingSessionService
{
    private const TTL_MINUTES = 720;

    public function markHostJoined(string $roomName): void
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return;
        }

        Cache::put($this->hostKey($roomName), true, now()->addMinutes(self::TTL_MINUTES));
    }

    public function hasHostJoined(string $roomName): bool
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return false;
        }

        return (bool) Cache::get($this->hostKey($roomName), false);
    }

    /**
     * @param  array<string, bool|string>  $settings
     */
    public function guestJoinBlockedMessage(array $settings, string $roomName): ?string
    {
        if ($this->hasHostJoined($roomName)) {
            return null;
        }

        if (!(bool) ($settings['join_before_host'] ?? false)) {
            return 'The host has not started this meeting yet. Please try again shortly.';
        }

        if ((bool) ($settings['waiting_room'] ?? false)) {
            return 'The host has not opened the meeting yet. Please wait until the host starts the session.';
        }

        return null;
    }

    private function hostKey(string $roomName): string
    {
        return 'admin_meeting_host_joined:' . $roomName;
    }
}
