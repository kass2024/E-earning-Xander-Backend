<?php

namespace Tests\Unit\Meetings;

use App\Services\Meetings\DailyPermissionPolicy;
use PHPUnit\Framework\TestCase;

class DailyPermissionPolicyTest extends TestCase
{
    public function test_attendee_cannot_send_media(): void
    {
        $policy = new DailyPermissionPolicy();
        $props = $policy->tokenPermissionProps(DailyPermissionPolicy::ROLE_ATTENDEE);

        $this->assertFalse($props['is_owner']);
        $this->assertTrue($props['start_audio_off']);
        $this->assertTrue($props['start_video_off']);
        $this->assertFalse($props['enable_screenshare']);
        $this->assertFalse($props['permissions']['canSend']);
        $this->assertFalse($props['permissions']['canAdmin']);
    }

    public function test_host_has_admin_and_send(): void
    {
        $policy = new DailyPermissionPolicy();
        $props = $policy->tokenPermissionProps(DailyPermissionPolicy::ROLE_HOST);

        $this->assertTrue($props['is_owner']);
        $this->assertTrue($props['permissions']['canSend']);
        $this->assertTrue($props['permissions']['canAdmin']);
        $this->assertTrue($props['enable_screenshare']);
    }

    public function test_speaking_grant_is_minimum_audio_by_default(): void
    {
        $policy = new DailyPermissionPolicy();
        $update = $policy->speakingGrantUpdate(true, false, false);

        $this->assertSame(['audio'], $update['canSend']);
        $this->assertSame(['canSend' => false], $policy->revokePublishUpdate());
    }

    public function test_trusted_context_role_is_used(): void
    {
        $policy = new DailyPermissionPolicy();
        $this->assertSame(
            DailyPermissionPolicy::ROLE_ATTENDEE,
            $policy->resolveRole(false, []),
        );
        $this->assertSame(
            DailyPermissionPolicy::ROLE_HOST,
            $policy->resolveRole(true, []),
        );
        $this->assertSame(
            DailyPermissionPolicy::ROLE_PANELIST,
            $policy->resolveRole(false, ['meeting_role' => 'panelist']),
        );
    }
}
