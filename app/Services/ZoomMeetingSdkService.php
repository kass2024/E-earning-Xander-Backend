<?php

namespace App\Services;

/**
 * Meeting SDK (embedded in-browser) credentials and JWT signatures.
 * Uses ZOOM_EMBED_CLIENT_ID / ZOOM_EMBED_CLIENT_SECRET only — never calls Zoom REST API.
 */
class ZoomMeetingSdkService
{
    public function isConfigured(): bool
    {
        return (string) config('services.zoom.sdk_key') !== ''
            && (string) config('services.zoom.sdk_secret') !== '';
    }

    /**
     * @return array{embed_ready: bool, sdk_key_preview: string|null, message: string|null}
     */
    public function configurationStatus(): array
    {
        $sdkKey = trim((string) config('services.zoom.sdk_key'));
        $sdkSecret = trim((string) config('services.zoom.sdk_secret'));

        if ($sdkKey === '' || $sdkSecret === '') {
            return [
                'embed_ready' => false,
                'sdk_key_preview' => null,
                'message' => 'Set ZOOM_EMBED_CLIENT_ID and ZOOM_EMBED_CLIENT_SECRET from your Zoom General app (Features → Embed). These are not the Server-to-Server OAuth credentials.',
            ];
        }

        return [
            'embed_ready' => true,
            'sdk_key_preview' => substr($sdkKey, 0, 6) . '…',
            'message' => null,
        ];
    }

    public function assertConfigured(): void
    {
        $status = $this->configurationStatus();
        if (!$status['embed_ready']) {
            throw new \RuntimeException($status['message'] ?? 'Zoom Meeting SDK is not configured.');
        }
    }

    /**
     * @return array{signature: string, sdk_key: string, meeting_number: string, password: string, user_name: string, user_email?: string|null, role: int, zak?: string|null}
     */
    public function buildJoinPayload(
        string $meetingNumber,
        string $userName,
        int $role = 0,
        ?string $password = null,
        ?string $zak = null,
        ?string $userEmail = null,
    ): array {
        $sdkKey = (string) config('services.zoom.sdk_key');
        $sdkSecret = (string) config('services.zoom.sdk_secret');

        if ($sdkKey === '' || $sdkSecret === '') {
            throw new \RuntimeException(
                'Zoom embedded meetings are not configured. Set ZOOM_EMBED_CLIENT_ID and ZOOM_EMBED_CLIENT_SECRET from your General app (Features → Embed → Production). These are separate from ZOOM_CLIENT_ID / ZOOM_CLIENT_SECRET.'
            );
        }

        $meetingNumber = preg_replace('/\D+/', '', $meetingNumber) ?: $meetingNumber;

        $payload = [
            'signature' => $this->generateSignature($sdkKey, $sdkSecret, $meetingNumber, $role),
            'sdk_key' => $sdkKey,
            'meeting_number' => $meetingNumber,
            'password' => (string) ($password ?? ''),
            'user_name' => $userName !== '' ? $userName : 'Guest',
            'role' => $role,
            'zak' => $zak,
        ];

        if ($userEmail !== null && trim($userEmail) !== '') {
            $payload['user_email'] = trim($userEmail);
        }

        return $payload;
    }

    public function generateSignature(string $sdkKey, string $sdkSecret, string $meetingNumber, int $role): string
    {
        $iat = time() - 30;
        $exp = $iat + 60 * 60 * 2;

        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode([
            // General app (OAuth/Embed) uses appKey; legacy Meeting SDK used sdkKey — include both.
            'appKey' => $sdkKey,
            'sdkKey' => $sdkKey,
            'mn' => $meetingNumber,
            'role' => $role,
            'iat' => $iat,
            'exp' => $exp,
            'tokenExp' => $exp,
        ], JSON_THROW_ON_ERROR));

        $hash = hash_hmac('sha256', $header . '.' . $payload, $sdkSecret, true);

        return $header . '.' . $payload . '.' . $this->base64UrlEncode($hash);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
