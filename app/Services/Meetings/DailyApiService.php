<?php

namespace App\Services\Meetings;

use App\Enums\MeetingProvider;
use App\Exceptions\Meetings\ProviderNotConfiguredException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DailyApiService
{
    public function isConfigured(): bool
    {
        return trim((string) $this->cfg('api_key', '')) !== ''
            && trim((string) $this->cfg('domain', '')) !== '';
    }

    public function domain(): string
    {
        return trim((string) $this->cfg('domain', ''));
    }

    public function webhookConfigured(): bool
    {
        return $this->isConfigured()
            && trim((string) $this->cfg('webhook_hmac', '')) !== '';
    }

    public function roomUrl(string $roomName): string
    {
        $domain = $this->domain();

        return 'https://' . rtrim($domain, '/') . '/' . ltrim($roomName, '/');
    }

    /**
     * Safe classroom room properties aligned with Daily SFU defaults.
     * Do NOT set sfu_switchover=0 (that forces fragile P2P).
     * @see https://docs.daily.co/docs/guides/architecture-and-monitoring/intro-to-video-arch
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function classroomRoomProperties(array $overrides = []): array
    {
        $base = [
            'enable_screenshare' => true,
            'enable_chat' => true,
            // App shows Zoom-style device picker; Daily's lobby blocks on missing camera.
            'enable_prejoin_ui' => false,
            // Hide "poor network" panel — noisy for classroom joins.
            'enable_network_ui' => false,
            'start_video_off' => true,
            'start_audio_off' => false,
            'lang' => (string) config('daily.default_language', 'en'),
        ];

        $merged = array_merge($base, $overrides);

        // Guard against accidental P2P lock-in from callers.
        if (array_key_exists('sfu_switchover', $merged) && (int) $merged['sfu_switchover'] === 0) {
            unset($merged['sfu_switchover']);
        }

        return $merged;
    }

    /**
     * Apply domain defaults from .env once per process/cache window.
     * Domain settings apply to all rooms; room properties can still override them.
     * @see https://docs.daily.co/reference/rest-api/domain
     *
     * @return array{ok: bool, synced?: bool, skipped?: string, config?: array<string, mixed>, message?: string}
     */
    public function ensureDomainDefaults(bool $force = false): array
    {
        if (!$this->isConfigured() || !(bool) config('daily.enabled', false)) {
            return ['ok' => false, 'skipped' => 'not_configured'];
        }

        $cacheKey = 'daily_domain_defaults_synced_v3';
        if (!$force) {
            try {
                if (\Illuminate\Support\Facades\Cache::get($cacheKey) === true) {
                    return ['ok' => true, 'synced' => false, 'skipped' => 'already_synced'];
                }
            } catch (\Throwable) {
                // cache may be unavailable
            }
        }

        $properties = [
            'lang' => (string) config('daily.default_language', 'en'),
            'enable_people_ui' => true,
            'enable_pip_ui' => true,
            // Custom Xander prejoin (device picker) replaces Daily's camera-required lobby.
            'enable_prejoin_ui' => false,
            'enable_network_ui' => false,
            // Empty string clears exit redirect so embedded Prebuilt Leave stays in-app.
            'redirect_on_meeting_exit' => (string) config('daily.redirect_on_meeting_exit', ''),
        ];

        try {
            $result = $this->setDomainConfig($properties);
            try {
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, 3600);
            } catch (\Throwable) {
                // ignore
            }

            return [
                'ok' => true,
                'synced' => true,
                'config' => is_array($result['config'] ?? null) ? $result['config'] : [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Daily domain defaults sync failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function getDomainConfig(): array
    {
        $this->assertConfigured();

        // Official endpoint: GET https://api.daily.co/v1/
        // https://docs.daily.co/reference/rest-api/domain/get-domain-config
        $response = $this->client()->get('/');
        $this->assertSuccessful($response, 'get', '/');

        return is_array($response->json()) ? $response->json() : [];
    }

    /**
     * Partial domain update (read-modify-write). Only pass keys you intend to change.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function setDomainConfig(array $properties): array
    {
        $this->assertConfigured();

        // Official endpoint: POST https://api.daily.co/v1/
        // https://docs.daily.co/reference/rest-api/domain/set-domain-config
        $response = $this->client()->post('/', [
            'properties' => $properties,
        ]);
        $this->assertSuccessful($response, 'post', '/');

        return is_array($response->json()) ? $response->json() : [];
    }

    /** Safe status for admin — never includes secrets. */
    public function domainStatus(): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'domain' => null,
                'api_reachable' => false,
            ];
        }

        try {
            $remote = $this->getDomainConfig();
            $config = is_array($remote['config'] ?? null) ? $remote['config'] : [];

            return [
                'configured' => true,
                'domain' => $this->domain(),
                'api_reachable' => true,
                'domain_name' => $remote['domain_name'] ?? null,
                'domain_id' => $remote['domain_id'] ?? null,
                'lang' => $config['lang'] ?? null,
                'enable_prejoin_ui' => $config['enable_prejoin_ui'] ?? null,
                'enable_people_ui' => $config['enable_people_ui'] ?? null,
                'redirect_on_meeting_exit' => $config['redirect_on_meeting_exit'] ?? null,
                // Architecture note for admins — Daily defaults to mesh SFU.
                'topology' => 'sfu',
                'topology_note' => 'Daily defaults to mesh SFU (recommended). Do not set sfu_switchover=0.',
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'domain' => $this->domain(),
                'api_reachable' => false,
                'error' => 'Daily API request failed.',
            ];
        }
    }

    public function configuredDomainSlug(): string
    {
        $domain = $this->domain();
        if ($domain === '') {
            return '';
        }

        return str_ends_with($domain, '.daily.co')
            ? substr($domain, 0, -strlen('.daily.co'))
            : $domain;
    }

    /** @return array<int, array<string, mixed>> */
    public function listWebhooks(): array
    {
        $response = $this->get('/webhooks');

        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return is_array($response) ? $response : [];
    }

    /**
     * @param  list<string>  $eventTypes
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $eventTypes, string $retryType = 'exponential', ?string $hmac = null): array
    {
        $payload = [
            'url' => $url,
            'eventTypes' => $eventTypes,
            'retryType' => $retryType,
        ];

        if ($hmac !== null && trim($hmac) !== '') {
            $payload['hmac'] = trim($hmac);
        }

        $response = $this->post('/webhooks', $payload);

        return is_array($response) ? $response : [];
    }

    /** @return array<string, mixed> */
    public function getWebhook(string $uuid): array
    {
        $response = $this->get('/webhooks/' . rawurlencode($uuid));

        return is_array($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function createRoom(string $roomName, array $properties = []): array
    {
        $this->assertConfigured();

        $response = $this->post('/rooms', [
            'name' => $roomName,
            'privacy' => 'private',
            'properties' => $properties,
        ]);

        if (!is_array($response) || empty($response['name'])) {
            throw new \RuntimeException('Daily room creation returned an invalid response.');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function updateRoom(string $roomName, array $properties): array
    {
        $this->assertConfigured();

        $response = $this->post('/rooms/' . rawurlencode($roomName), [
            'properties' => $properties,
        ]);

        return is_array($response) ? $response : [];
    }

    public function deleteRoom(string $roomName): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->delete('/rooms/' . rawurlencode($roomName));

            return true;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), '404')) {
                return true;
            }

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function createMeetingToken(array $properties): string
    {
        $this->assertConfigured();

        $response = $this->post('/meeting-tokens', [
            'properties' => $properties,
        ]);

        $token = is_array($response) ? ($response['token'] ?? null) : null;
        if (!is_string($token) || trim($token) === '') {
            throw new \RuntimeException('Daily did not return a meeting token.');
        }

        return $token;
    }

    /**
     * Ensure the room allows cloud recording, then start it.
     * @see https://docs.daily.co/reference/rest-api/rooms/recordings/start
     *
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, status?: string, message?: string, result?: array<string, mixed>}
     */
    public function startRoomRecording(string $roomName, array $options = []): array
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return ['ok' => false, 'message' => 'Daily room name is missing.'];
        }

        try {
            $this->updateRoom($roomName, $this->classroomRoomProperties([
                'enable_recording' => 'cloud',
            ]));
        } catch (\Throwable $e) {
            // Room may already allow recording; continue to start.
            Log::info('Daily enable_recording update skipped', [
                'room' => $roomName,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $payload = array_merge([
                'type' => 'cloud',
                'layout' => ['preset' => 'default'],
            ], $options);
            $result = $this->post('/rooms/' . rawurlencode($roomName) . '/recordings/start', $payload);

            return [
                'ok' => true,
                'status' => (string) ($result['status'] ?? 'sent'),
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage() ?: 'Daily could not start cloud recording. Enable cloud recording on your Daily plan/domain.',
            ];
        }
    }

    /**
     * @see https://docs.daily.co/reference/rest-api/rooms/recordings/stop
     *
     * @return array{ok: bool, status?: string, message?: string, result?: array<string, mixed>}
     */
    public function stopRoomRecording(string $roomName): array
    {
        $roomName = trim($roomName);
        if ($roomName === '') {
            return ['ok' => false, 'message' => 'Daily room name is missing.'];
        }

        try {
            $result = $this->post('/rooms/' . rawurlencode($roomName) . '/recordings/stop', []);

            return [
                'ok' => true,
                'status' => (string) ($result['status'] ?? 'sent'),
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage() ?: 'Daily could not stop cloud recording.',
            ];
        }
    }

    public function generateRoomName(
        int $institutionId,
        int $courseId,
        ?int $materialId = null,
        ?int $hostUserId = null,
    ): string {
        $suffix = Str::lower(Str::random(8));
        $hostPart = $hostUserId && $hostUserId > 0 ? '-h-' . $hostUserId : '';

        // Unique per institution/course/material/host so multiple institutions/instructors can host concurrently.
        $instPart = $institutionId > 0 ? (string) $institutionId : 'main';

        return 'inst-' . $instPart
            . '-class-' . $courseId
            . ($materialId ? '-m-' . $materialId : '')
            . $hostPart
            . '-' . $suffix;
    }

    public function webhookUrl(): string
    {
        $base = rtrim((string) $this->cfg('webhook_base_url', config('app.url')), '/');

        return $base . '/api/webhooks/daily';
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        $this->assertConfigured();

        $response = $this->client()->get($path, $query);
        $this->assertSuccessful($response, 'get', $path);

        return is_array($response->json()) ? $response->json() : [];
    }

    /** @param  array<string, mixed>  $payload */
    public function post(string $path, array $payload = []): array
    {
        $this->assertConfigured();

        $response = $this->client()->post($path, $payload);
        $this->assertSuccessful($response, 'post', $path);

        return is_array($response->json()) ? $response->json() : [];
    }

    public function delete(string $path): void
    {
        $this->assertConfigured();

        $response = $this->client()->delete($path);
        if (!$response->successful() && $response->status() !== 404) {
            $this->logFailure('delete', $path, $response->status(), $response->json());
            throw new \RuntimeException($this->extractErrorMessage($response->json()) ?: 'Daily API delete failed.');
        }
    }

    protected function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw ProviderNotConfiguredException::forProvider(MeetingProvider::Daily->value);
        }
    }

    protected function client()
    {
        $baseUrl = rtrim((string) $this->cfg('base_url', 'https://api.daily.co/v1'), '/');

        return Http::withToken((string) $this->cfg('api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 200, static fn ($exception, $request) => $request instanceof \Illuminate\Http\Client\RequestException
                && $request->response?->serverError())
            ->baseUrl($baseUrl);
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return config('daily.' . $key, config('services.daily.' . $key, $default));
    }

    protected function assertSuccessful(\Illuminate\Http\Client\Response $response, string $operation, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        $this->logFailure($operation, $path, $response->status(), $response->json());
        throw new \RuntimeException($this->extractErrorMessage($response->json()) ?: 'Daily API request failed.');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function extractErrorMessage(?array $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $message = $body['error'] ?? $body['info'] ?? $body['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function logFailure(string $operation, string $target, int $status, ?array $body): void
    {
        Log::warning('Daily API request failed', [
            'provider' => MeetingProvider::Daily->value,
            'operation' => $operation,
            'target' => $target,
            'status' => $status,
            'error' => $this->extractErrorMessage($body),
        ]);
    }
}
