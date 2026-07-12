<?php

namespace Tests\Feature;

use App\Jobs\ProcessDailyWebhookEvent;
use App\Services\Meetings\DailyWebhookSignatureVerifier;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailyWebhookTest extends TestCase
{
    public function test_daily_webhook_test_payload_returns_200(): void
    {
        $response = $this->postJson('/api/webhooks/daily', ['test' => 'test']);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_daily_webhook_rejects_invalid_signature_when_hmac_configured(): void
    {
        config(['daily.webhook_hmac' => base64_encode('test-secret-key-32bytes-long!!')]);

        $response = $this->postJson('/api/webhooks/daily', [
            'id' => 'evt-1',
            'type' => 'meeting.started',
            'payload' => ['room' => 'room-1'],
        ], [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Signature' => 'invalid',
        ]);

        $response->assertStatus(401);
    }

    public function test_hmac_secret_is_base64_decoded(): void
    {
        $rawSecret = random_bytes(32);
        config(['daily.webhook_hmac' => base64_encode($rawSecret)]);

        $verifier = app(DailyWebhookSignatureVerifier::class);
        $timestamp = (string) time();
        $body = '{"id":"evt-2","type":"meeting.ended"}';
        $signature = base64_encode(hash_hmac('sha256', $timestamp . '.' . $body, $rawSecret, true));

        $this->assertTrue($verifier->verify($body, $timestamp, $signature));
    }

    public function test_daily_webhook_queues_processing_for_signed_event_when_database_available(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database driver unavailable in this environment.');
        }

        $secret = base64_encode(random_bytes(32));
        config(['daily.webhook_hmac' => $secret]);

        $timestamp = (string) time();
        $body = json_encode([
            'id' => 'evt-valid-' . uniqid(),
            'type' => 'participant.joined',
            'payload' => ['room' => 'inst-1-class-2-abc', 'user_id' => '42'],
        ], JSON_UNESCAPED_SLASHES);

        $decoded = base64_decode($secret, true);
        $signature = base64_encode(hash_hmac('sha256', $timestamp . '.' . $body, $decoded, true));

        Queue::fake();

        $response = $this->call(
            'POST',
            '/api/webhooks/daily',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Webhook-Timestamp' => $timestamp,
                'HTTP_X-Webhook-Signature' => $signature,
            ],
            $body,
        );

        $response->assertOk();
        Queue::assertPushed(ProcessDailyWebhookEvent::class);
    }

    protected function databaseAvailable(): bool
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
