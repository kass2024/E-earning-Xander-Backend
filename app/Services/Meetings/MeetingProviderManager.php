<?php

namespace App\Services\Meetings;

use App\Contracts\MeetingProviderInterface;
use App\Enums\MeetingProvider;
use App\Models\CourseMaterial;
use App\Models\PlatformInstitution;
use App\Services\PlatformSettingsService;
use App\Support\CourseMaterialHelper;

class MeetingProviderManager
{
    public function __construct(
        private readonly ZoomMeetingProvider $zoom,
        private readonly DailyMeetingProvider $daily,
        private readonly MeetingProviderStatusService $status,
    ) {}

    public function forInstitution(?PlatformInstitution $institution): MeetingProviderInterface
    {
        return $this->forProvider($this->institutionProvider($institution));
    }

    public function forProvider(MeetingProvider $provider): MeetingProviderInterface
    {
        return match ($provider) {
            MeetingProvider::Daily => $this->daily,
            MeetingProvider::Zoom => $this->zoom,
        };
    }

    public function resolveForMaterial(CourseMaterial $material, ?PlatformInstitution $institution = null): MeetingProviderInterface
    {
        return $this->forProvider(CourseMaterialHelper::meetingProvider($material, $institution));
    }

    public function institutionProvider(?PlatformInstitution $institution): MeetingProvider
    {
        if ($institution) {
            return MeetingProvider::fromStringOrDefault($institution->meeting_provider);
        }

        return app(PlatformSettingsService::class)->mainPlatformMeetingProvider();
    }

    public function assertSelectable(MeetingProvider $provider): void
    {
        if (!$this->status->isSelectable($provider)) {
            throw new \InvalidArgumentException(
                "Meeting provider \"{$provider->value}\" is not available. Check server configuration."
            );
        }
    }
}
