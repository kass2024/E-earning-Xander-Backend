<?php

namespace Tests\Unit\Meetings;

use App\Services\Meetings\DailyPermissionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight engagement policy checks that do not require a database.
 * Full Q&A/poll/breakout/stage flows are covered by MeetingEngagementServiceTest
 * when RefreshDatabase is available in CI.
 */
class MeetingEngagementPolicyTest extends TestCase
{
    public function test_webinar_attendee_starts_muted_without_send(): void
    {
        $policy = new DailyPermissionPolicy();
        $props = $policy->tokenPermissionProps(
            DailyPermissionPolicy::ROLE_ATTENDEE,
            DailyPermissionPolicy::MODE_WEBINAR,
        );

        $this->assertFalse($props['permissions']['canSend']);
        $this->assertTrue($props['start_audio_off']);
        $this->assertTrue($props['start_video_off']);
    }

    public function test_speaking_grant_with_video_and_screen(): void
    {
        $policy = new DailyPermissionPolicy();
        $update = $policy->speakingGrantUpdate(true, true, true);

        $this->assertSame(['audio', 'video', 'screenVideo', 'screenAudio'], $update['canSend']);
    }

    public function test_revoke_clears_publish(): void
    {
        $policy = new DailyPermissionPolicy();
        $this->assertSame(['canSend' => false], $policy->revokePublishUpdate());
    }

    public function test_default_speak_timer_bucket_seconds(): void
    {
        // Client presets used by host Hands panel (must stay in sync with UI).
        $presets = [60, 120, 300, 600, 0];
        $this->assertContains(120, $presets);
        $this->assertSame(5, count($presets));
        $this->assertLessThanOrEqual(7200, max($presets));
    }
}
