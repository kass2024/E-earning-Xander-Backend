<?php

namespace Tests\Unit\Meetings;

use App\Services\Meetings\DailyPermissionPolicy;
use App\Support\MeetingSettingsMapper;
use PHPUnit\Framework\TestCase;

class MeetingSettingsMapperTest extends TestCase
{
    public function test_waiting_room_defaults_to_inverse_of_join_before_host(): void
    {
        $settings = MeetingSettingsMapper::normalize(['join_before_host' => true]);
        $this->assertTrue($settings['join_before_host']);
        $this->assertFalse($settings['waiting_room']);

        $settings = MeetingSettingsMapper::normalize(['join_before_host' => false]);
        $this->assertFalse($settings['join_before_host']);
        $this->assertTrue($settings['waiting_room']);
    }

    public function test_daily_room_properties_honor_media_and_recording_toggles(): void
    {
        $settings = MeetingSettingsMapper::normalize([
            'mute_upon_entry' => true,
            'participant_video' => true,
            'auto_recording' => true,
        ]);

        $props = MeetingSettingsMapper::dailyRoomProperties($settings);

        $this->assertTrue($props['start_audio_off']);
        $this->assertFalse($props['start_video_off']);
        $this->assertSame('cloud', $props['enable_recording']);
    }

    public function test_token_props_respect_host_and_participant_video(): void
    {
        $policy = new DailyPermissionPolicy();
        $settings = MeetingSettingsMapper::normalize([
            'host_video' => false,
            'participant_video' => false,
            'mute_upon_entry' => false,
        ]);

        $host = MeetingSettingsMapper::applyToTokenProps(
            $policy->tokenPermissionProps(DailyPermissionPolicy::ROLE_HOST),
            $settings,
            DailyPermissionPolicy::ROLE_HOST,
        );
        $this->assertTrue($host['start_video_off']);

        $attendee = MeetingSettingsMapper::applyToTokenProps(
            $policy->tokenPermissionProps(DailyPermissionPolicy::ROLE_ATTENDEE),
            $settings,
            DailyPermissionPolicy::ROLE_ATTENDEE,
        );
        $this->assertTrue($attendee['start_video_off']);
        $this->assertFalse($attendee['start_audio_off']);
        $this->assertTrue($attendee['enable_screenshare']);
        $this->assertTrue($attendee['permissions']['canSend']);
    }
}
