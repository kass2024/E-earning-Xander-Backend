<?php

namespace App\Services\Meetings;

class DailyWebhookSignatureVerifier
{
    /** Maximum age of webhook timestamp (seconds). Daily retries may arrive delayed. */
    public const TIMESTAMP_TOLERANCE_SECONDS = 600;

    public function isConfigured(): bool
    {
        return trim((string) config('daily.webhook_hmac', '')) !== '';
    }

    public function isTestPayload(array $payload): bool
    {
        return ($payload['test'] ?? null) === 'test';
    }

    /**
     * Verify Daily webhook HMAC per official docs:
     * signature input = X-Webhook-Timestamp + "." + raw JSON body
     * HMAC-SHA256 with Base64-decoded secret, compare Base64 digest.
     */
    public function verify(string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $timestamp = trim((string) $timestamp);
        $signature = trim((string) $signature);

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (!$this->isTimestampFresh($timestamp)) {
            return false;
        }

        $secret = base64_decode((string) config('daily.webhook_hmac'), true);
        if ($secret === false || $secret === '') {
            return false;
        }

        $signatureInput = $timestamp . '.' . $rawBody;
        $computed = base64_encode(hash_hmac('sha256', $signatureInput, $secret, true));

        return hash_equals($computed, $signature);
    }

    public function isTimestampFresh(string $timestamp): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $age = abs(time() - (int) $timestamp);

        return $age <= self::TIMESTAMP_TOLERANCE_SECONDS;
    }
}
