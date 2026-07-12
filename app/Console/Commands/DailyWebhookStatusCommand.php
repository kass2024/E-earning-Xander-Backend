<?php

namespace App\Console\Commands;

use App\Services\Meetings\DailyApiService;
use Illuminate\Console\Command;

class DailyWebhookStatusCommand extends Command
{
    protected $signature = 'daily:webhook:status {uuid?}';

    protected $description = 'Show Daily webhook configuration status';

    public function handle(DailyApiService $daily): int
    {
        if (!$daily->isConfigured()) {
            $this->error('Daily is not configured.');

            return self::FAILURE;
        }

        $uuid = (string) ($this->argument('uuid') ?: config('daily.webhook_uuid', ''));
        if ($uuid === '') {
            $webhooks = $daily->listWebhooks();
            if ($webhooks === []) {
                $this->warn('No webhooks found. Run: php artisan daily:webhook:configure');

                return self::SUCCESS;
            }

            foreach ($webhooks as $hook) {
                $this->renderWebhook(is_array($hook) ? $hook : []);
            }

            return self::SUCCESS;
        }

        try {
            $hook = $daily->getWebhook($uuid);
        } catch (\Throwable $e) {
            $this->error('Could not load webhook: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->renderWebhook($hook);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $hook */
    protected function renderWebhook(array $hook): void
    {
        $this->line('UUID: ' . (string) ($hook['uuid'] ?? 'n/a'));
        $this->line('URL: ' . (string) ($hook['url'] ?? 'n/a'));
        $this->line('State: ' . (string) ($hook['state'] ?? 'n/a'));
        $this->line('Retry type: ' . (string) ($hook['retryType'] ?? $hook['retry_type'] ?? 'n/a'));
        $this->line('Failed count: ' . (string) ($hook['failedCount'] ?? '0'));
        $this->line('Last push: ' . (string) ($hook['lastMomentPushed'] ?? 'n/a'));
        $events = $hook['eventTypes'] ?? [];
        $this->line('Events: ' . (is_array($events) ? implode(', ', $events) : 'n/a'));
        $this->line('Domain ID: ' . (string) ($hook['domainId'] ?? 'n/a'));
        $this->newLine();
    }
}
