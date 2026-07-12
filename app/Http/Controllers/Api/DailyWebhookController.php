<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDailyWebhookEvent;
use App\Models\ProviderWebhookEvent;
use App\Services\Meetings\DailyWebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DailyWebhookController extends Controller
{
    public function handle(Request $request, DailyWebhookSignatureVerifier $verifier)
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        if ($verifier->isTestPayload($payload)) {
            return response()->json(['ok' => true]);
        }

        if ($verifier->isConfigured()) {
            $valid = $verifier->verify(
                $rawBody,
                $request->header('X-Webhook-Timestamp'),
                $request->header('X-Webhook-Signature'),
            );

            if (!$valid) {
                Log::warning('Daily webhook rejected: invalid signature');

                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? $payload['event'] ?? 'unknown');

        if ($eventId === '') {
            Log::info('Daily webhook missing event id', ['event_type' => $eventType]);

            return response()->json(['ok' => true]);
        }

        try {
            $record = ProviderWebhookEvent::query()->create([
                'provider' => 'daily',
                'external_event_id' => $eventId,
                'event_type' => $eventType,
                'received_at' => now(),
                'processing_status' => 'pending',
                'payload' => $payload,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }

            throw $e;
        }

        ProcessDailyWebhookEvent::dispatch($record->id);

        return response()->json(['ok' => true]);
    }
}
