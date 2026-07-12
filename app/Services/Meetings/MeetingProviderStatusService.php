<?php

namespace App\Services\Meetings;

use App\Enums\MeetingProvider;
use App\Services\ZoomService;

class MeetingProviderStatusService
{
    public function __construct(
        private readonly ZoomService $zoom,
        private readonly DailyApiService $daily,
    ) {}

    public function isZoomConfigured(): bool
    {
        $status = $this->zoom->configurationStatus();

        return !empty($status['api_ready']);
    }

    public function isDailyConfigured(): bool
    {
        return (bool) config('daily.enabled', config('services.daily.integration_enabled', false))
            && $this->daily->isConfigured();
    }

    public function isSelectable(MeetingProvider $provider): bool
    {
        return match ($provider) {
            MeetingProvider::Zoom => $this->isZoomConfigured(),
            MeetingProvider::Daily => $this->isDailyConfigured(),
        };
    }

    public function dailyWebhookUrl(): string
    {
        $base = rtrim((string) config('services.daily.webhook_base_url', config('app.url')), '/');

        return $base . '/api/webhooks/daily';
    }

    /** @return array<string, mixed> */
    public function dailyWebhookInfo(): array
    {
        $secretConfigured = trim((string) config('services.daily.webhook_secret', '')) !== '';

        return [
            'url' => $this->dailyWebhookUrl(),
            'secret_configured' => $secretConfigured,
            'events_hint' => [
                'meeting.started',
                'meeting.ended',
                'participant.joined',
                'participant.left',
                'recording.ready',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $mainProvider = app(\App\Services\PlatformSettingsService::class)->mainPlatformMeetingProvider();

        return [
            'integration_enabled' => [
                'daily' => (bool) config('services.daily.integration_enabled', false),
            ],
            'main_platform_meeting_provider' => $mainProvider->value,
            'providers' => [
                'zoom' => [
                    'configured' => $this->isZoomConfigured(),
                ],
                'daily' => [
                    'enabled' => $this->isDailyConfigured(),
                    'configured' => $this->isDailyConfigured(),
                    'domain' => $this->isDailyConfigured() ? $this->daily->domain() : null,
                    'webhook_configured' => $this->daily->webhookConfigured(),
                    'recording_enabled' => (bool) config('daily.recording_enabled', false),
                ],
            ],
            'available_meeting_providers' => array_values(array_filter(
                MeetingProvider::values(),
                fn (string $value) => $this->isSelectable(MeetingProvider::fromStringOrDefault($value)),
            )),
        ];
    }
}
