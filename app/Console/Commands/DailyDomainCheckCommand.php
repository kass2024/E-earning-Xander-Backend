<?php

namespace App\Console\Commands;

use App\Services\Meetings\DailyApiService;
use Illuminate\Console\Command;

class DailyDomainCheckCommand extends Command
{
    protected $signature = 'daily:domain:check {--sync : Apply safe domain defaults (lang, redirect) from .env}';

    protected $description = 'Check Daily.co domain configuration and API connectivity';

    public function handle(DailyApiService $daily): int
    {
        $enabled = (bool) config('daily.enabled', false);
        $this->line('Daily integration enabled: ' . ($enabled ? 'yes' : 'no'));

        if (!$enabled) {
            $this->warn('Set DAILY_INTEGRATION_ENABLED=true to use Daily.');

            return self::SUCCESS;
        }

        $domain = $daily->domain();
        $this->line('Configured domain: ' . ($domain !== '' ? $domain : '(missing)'));
        $this->line('API configured: ' . ($daily->isConfigured() ? 'yes' : 'no'));
        $this->line('Webhook HMAC configured: ' . ($daily->webhookConfigured() ? 'yes' : 'no'));
        $this->line('Recording enabled: ' . (config('daily.recording_enabled') ? 'yes' : 'no'));
        $this->line('Default language (.env): ' . (string) config('daily.default_language', 'en'));

        if (!$daily->isConfigured()) {
            $this->error('Daily API key and domain are required.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            return $this->syncDomainDefaults($daily);
        }

        try {
            // Keep domain defaults in sync with .env (lang, exit redirect) — see Daily domain docs.
            $sync = $daily->ensureDomainDefaults();
            if (!empty($sync['synced'])) {
                $this->info('Domain defaults synced from .env.');
            }

            $status = $daily->domainStatus();
            $this->info('Daily API connectivity: OK');
            $this->line('Domain name (API): ' . (string) ($status['domain_name'] ?? 'n/a'));
            $this->line('Domain ID: ' . (string) ($status['domain_id'] ?? 'n/a'));
            $this->line('Domain language (API): ' . (string) ($status['lang'] ?? 'n/a'));
            $this->line('Prejoin UI (API): ' . json_encode($status['enable_prejoin_ui'] ?? null));
            $this->line('People UI (API): ' . json_encode($status['enable_people_ui'] ?? null));
            $this->line('Redirect on exit (API): ' . (string) ($status['redirect_on_meeting_exit'] ?? '(not set)'));
            $this->line('Topology: ' . (string) ($status['topology'] ?? 'sfu'));

            $expectedSlug = $daily->configuredDomainSlug();
            $remoteSlug = (string) ($status['domain_name'] ?? '');
            if ($expectedSlug !== '' && $remoteSlug !== '' && $expectedSlug !== $remoteSlug) {
                $this->warn("DAILY_DOMAIN slug \"{$expectedSlug}\" does not match API domain_name \"{$remoteSlug}\".");
            } elseif ($expectedSlug !== '' && $remoteSlug !== '') {
                $this->info('Domain slug matches API: ' . $expectedSlug);
            }

            $this->comment('Per Daily docs: domain settings apply to all rooms; room properties override domain.');
            $this->comment('Architecture: mesh SFU is the default (do not set sfu_switchover=0).');
            $this->comment('Refs: https://docs.daily.co/reference/rest-api/domain');
            $this->comment('      https://docs.daily.co/docs/guides/architecture-and-monitoring/intro-to-video-arch');
        } catch (\Throwable $e) {
            $this->error('Daily API connectivity failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function syncDomainDefaults(DailyApiService $daily): int
    {
        $result = $daily->ensureDomainDefaults(true);
        if (!($result['ok'] ?? false)) {
            $this->error('Domain sync failed: ' . (string) ($result['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Domain defaults updated (lang, redirect_on_meeting_exit, Prebuilt UI flags).');
        $config = is_array($result['config'] ?? null) ? $result['config'] : [];
        $this->line('Language: ' . (string) ($config['lang'] ?? config('daily.default_language')));
        $this->line('Redirect on exit: ' . (string) ($config['redirect_on_meeting_exit'] ?? config('daily.redirect_on_meeting_exit')));

        return self::SUCCESS;
    }
}
