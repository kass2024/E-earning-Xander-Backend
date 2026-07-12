<?php

namespace App\Jobs;

use App\Models\ProviderWebhookEvent;
use App\Services\Meetings\DailyWebhookEventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDailyWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public int $webhookEventId,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(DailyWebhookEventDispatcher $dispatcher): void
    {
        $event = ProviderWebhookEvent::query()->find($this->webhookEventId);
        if (!$event || $event->processing_status === 'processed') {
            return;
        }

        $event->increment('attempts');

        try {
            $dispatcher->dispatch($event);
            $event->forceFill([
                'processing_status' => 'processed',
                'processed_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (\Throwable $e) {
            $event->forceFill([
                'processing_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::warning('Daily webhook event processing failed', [
                'event_id' => $event->external_event_id,
                'event_type' => $event->event_type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
