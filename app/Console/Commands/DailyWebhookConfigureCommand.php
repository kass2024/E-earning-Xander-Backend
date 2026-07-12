<?php

namespace App\Console\Commands;

use App\Services\Meetings\DailyApiService;
use Illuminate\Console\Command;

class DailyWebhookConfigureCommand extends Command
{
    protected $signature = 'daily:webhook:configure {--url=} {--hmac=}';

    protected $description = 'Register the Daily webhook endpoint (requires billing/card on Daily account)';

    /** @var list<string> */
    protected array $defaultEvents = [
        'meeting.started',
        'meeting.ended',
        'participant.joined',
        'participant.left',
        'recording.started',
        'recording.ready-to-download',
        'recording.error',
    ];

    public function handle(DailyApiService $daily): int
    {
        if (!(bool) config('daily.enabled', false)) {
            $this->error('Daily integration is disabled.');

            return self::FAILURE;
        }

        if (!$daily->isConfigured()) {
            $this->error('Daily API key and domain are required.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: $daily->webhookUrl());
        $hmac = $this->option('hmac') ?: config('daily.webhook_hmac');

        $this->line('Webhook URL: ' . $url);
        $this->warn('Daily requires a credit card on the account before webhooks are available.');

        try {
            $result = $daily->createWebhook(
                $url,
                $this->defaultEvents,
                (string) config('daily.webhook_retry_type', 'exponential'),
                is_string($hmac) && $hmac !== '' ? $hmac : null,
            );
        } catch (\Throwable $e) {
            $this->error('Webhook registration failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $uuid = (string) ($result['uuid'] ?? '');
        $returnedHmac = (string) ($result['hmac'] ?? '');

        $this->info('Webhook registered successfully.');
        if ($uuid !== '') {
            $this->line('Webhook UUID: ' . $uuid);
            $this->line('Save DAILY_WEBHOOK_UUID=' . $uuid . ' in your server .env');
        }

        if ($returnedHmac !== '') {
            $this->line('Save DAILY_WEBHOOK_HMAC=' . $returnedHmac . ' in your server .env');
            $this->warn('The HMAC secret is shown once — store it securely and never commit it.');
        }

        return self::SUCCESS;
    }
}
