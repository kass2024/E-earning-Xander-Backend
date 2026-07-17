<?php

namespace App\Providers;

use App\Services\DatabaseSchemaService;
use App\Services\InstitutionMailResolver;
use App\Services\MailDeliveryService;
use App\Services\StripePaymentService;
use App\Services\Meetings\MeetingProviderManager;
use App\Services\Meetings\MeetingProviderStatusService;
use App\Services\Meetings\DailyWebhookEventDispatcher;
use App\Services\Meetings\DailyWebhookSignatureVerifier;
use App\Services\Meetings\DailyApiService;
use App\Services\Meetings\DailyMeetingProvider;
use App\Services\Meetings\ZoomMeetingProvider;
use App\Services\Meetings\LiveMeetingJoinService;
use App\Services\Meetings\AdminZoomMeetingJoinService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseSchemaService::class);
        $this->app->singleton(MailDeliveryService::class);
        $this->app->singleton(InstitutionMailResolver::class);
        $this->app->singleton(StripePaymentService::class);
        $this->app->singleton(DailyApiService::class);
        $this->app->singleton(DailyWebhookSignatureVerifier::class);
        $this->app->singleton(DailyWebhookEventDispatcher::class);
        $this->app->singleton(DailyMeetingProvider::class);
        $this->app->singleton(ZoomMeetingProvider::class);
        $this->app->singleton(MeetingProviderStatusService::class);
        $this->app->singleton(MeetingProviderManager::class);
        $this->app->singleton(LiveMeetingJoinService::class);
        $this->app->singleton(AdminZoomMeetingJoinService::class);
    }

    public function boot(): void
    {
        $this->ensureStorageDirectories();

        $localDomain = config('mail.mailers.smtp.local_domain');
        if (empty($localDomain) || $localDomain === 'localhost') {
            $from = config('mail.from.address');
            if (is_string($from) && str_contains($from, '@')) {
                config(['mail.mailers.smtp.local_domain' => substr(strrchr($from, '@'), 1)]);
            }
        }

        if (!config('app.auto_migrate')) {
            return;
        }

        if ($this->app->runningInConsole() && !DatabaseSchemaService::shouldAutoMigrateCli()) {
            return;
        }

        try {
            /** @var DatabaseSchemaService $schema */
            $schema = $this->app->make(DatabaseSchemaService::class);

            if (!$schema->databaseConnected()) {
                return;
            }

            $schema->ensureMigrated();
            $schema->ensureDemoData();
        } catch (\Throwable $e) {
            Log::warning('AUTO_MIGRATE skipped', ['error' => $e->getMessage()]);
        }
    }

    private function ensureStorageDirectories(): void
    {
        foreach ([
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        $compiled = storage_path('framework/views');
        if (config('view.compiled') === null || config('view.compiled') === '') {
            config(['view.compiled' => $compiled]);
        }
    }
}
