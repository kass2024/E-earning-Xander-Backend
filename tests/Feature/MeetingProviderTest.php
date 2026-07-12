<?php

namespace Tests\Feature;

use App\Enums\MeetingProvider;
use App\Models\PlatformInstitution;
use App\Services\Meetings\DailyApiService;
use App\Services\Meetings\MeetingProviderManager;
use App\Services\Meetings\MeetingProviderStatusService;
use App\Support\CourseMaterialHelper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeetingProviderTest extends TestCase
{
    public function test_institution_model_accepts_zoom_provider(): void
    {
        $institution = new PlatformInstitution([
            'meeting_provider' => 'zoom',
        ]);

        $this->assertSame('zoom', $institution->meeting_provider);
    }

    public function test_meeting_provider_manager_falls_back_to_zoom_without_institution(): void
    {
        $settings = \Mockery::mock(\App\Services\PlatformSettingsService::class);
        $settings->shouldReceive('mainPlatformMeetingProvider')->andReturn(MeetingProvider::Zoom);
        $this->app->instance(\App\Services\PlatformSettingsService::class, $settings);

        $manager = app(MeetingProviderManager::class);

        $this->assertSame(MeetingProvider::Zoom, $manager->institutionProvider(null));
    }

    public function test_course_material_without_provider_metadata_defaults_to_zoom(): void
    {
        $material = new \App\Models\CourseMaterial([
            'type' => 'zoom',
            'metadata' => ['meeting_id' => '123'],
        ]);

        $this->assertSame(MeetingProvider::Zoom, CourseMaterialHelper::meetingProvider($material));
    }

    public function test_course_material_uses_stored_provider(): void
    {
        $material = new \App\Models\CourseMaterial([
            'type' => 'zoom',
            'metadata' => ['meeting_provider' => 'daily', 'meeting_id' => 'room-1'],
        ]);

        $this->assertSame(MeetingProvider::Daily, CourseMaterialHelper::meetingProvider($material));
    }

    public function test_daily_api_create_room_uses_http_fake(): void
    {
        config([
            'daily.enabled' => true,
            'services.daily.integration_enabled' => true,
            'services.daily.api_key' => 'test-key',
            'services.daily.domain' => 'example.daily.co',
        ]);

        Http::fake([
            'https://api.daily.co/v1/rooms' => Http::response([
                'name' => 'inst-1-course-2-abc',
                'url' => 'https://example.daily.co/inst-1-course-2-abc',
            ], 200),
        ]);

        $room = app(DailyApiService::class)->createRoom('inst-1-course-2-abc', ['exp' => time() + 3600]);

        $this->assertSame('inst-1-course-2-abc', $room['name']);
        Http::assertSent(static fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/rooms'));
    }

    public function test_legacy_meeting_without_provider_defaults_to_zoom(): void
    {
        $material = new \App\Models\CourseMaterial([
            'type' => 'zoom',
            'metadata' => ['meeting_id' => '999888777'],
        ]);

        $this->assertSame(MeetingProvider::Zoom, CourseMaterialHelper::meetingProvider($material));
    }

    public function test_old_zoom_meeting_stays_zoom_after_institution_switches_to_daily(): void
    {
        $institution = new PlatformInstitution(['meeting_provider' => 'daily']);
        $material = new \App\Models\CourseMaterial([
            'type' => 'zoom',
            'metadata' => [
                'meeting_provider' => 'zoom',
                'meeting_id' => '123456789',
            ],
        ]);

        $this->assertSame(MeetingProvider::Zoom, CourseMaterialHelper::meetingProvider($material, $institution));
    }

    public function test_daily_not_selectable_when_integration_disabled(): void
    {
        config([
            'daily.enabled' => false,
            'services.daily.integration_enabled' => false,
            'services.daily.api_key' => 'test-key',
            'services.daily.domain' => 'example.daily.co',
        ]);

        $status = app(MeetingProviderStatusService::class);

        $this->assertFalse($status->isSelectable(MeetingProvider::Daily));
    }
}
