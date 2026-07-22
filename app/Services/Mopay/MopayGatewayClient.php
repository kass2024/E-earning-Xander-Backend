<?php

namespace App\Services\Mopay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Portable MoPay Gateway V1 client (aligned with parrot_mis payments/mopay/auth.php + start.php).
 *
 * Copy this class into any Laravel app and point config at that project's own MOPAY_* env vars.
 * Each project must use its own merchant credentials, callback URL, and signing key so webhook
 * registration on /api/v1/user/settings does not overwrite another product.
 */
class MopayGatewayClient
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>|null  $config  Defaults to config('services.mopay')
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('services.mopay', []);
    }

    /**
     * Build a client from an arbitrary config array (useful for multi-tenant / other projects).
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['auth_key'] ?? '')) !== ''
            && trim((string) ($this->config['server_base_url'] ?? '')) !== '';
    }

    public function projectSlug(): string
    {
        $slug = preg_replace('/[^a-z0-9_-]+/i', '_', (string) ($this->config['project_slug'] ?? 'app')) ?: 'app';

        return strtolower($slug);
    }

    public function serverBaseUrl(): string
    {
        return rtrim((string) ($this->config['server_base_url'] ?? ''), '/');
    }

    /**
     * Rwanda MoMo MSISDN as 12 digits: 2507XXXXXXXX (same as parrot_mis).
     */
    public function normalizeMsisdn(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '250') && strlen($digits) >= 12) {
            return substr($digits, 0, 12);
        }
        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            return '250' . substr($digits, 1, 9);
        }
        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            return '250' . $digits;
        }

        return $digits;
    }

    /**
     * Value for the Authorization header (no "Authorization:" prefix).
     *
     * Matches parrot_mis mopay_get_authorization_value():
     * 1) MOPAY_BEARER_TOKEN if set
     * 2) Cached /token access_token (Bearer)
     * 3) Fallback: raw MOPAY_AUTH_KEY (NOT "Basic " wrapped)
     */
    public function authorizationHeader(): string
    {
        $bearer = trim((string) ($this->config['bearer_token'] ?? ''));
        if ($bearer !== '') {
            return stripos($bearer, 'bearer ') === 0 ? $bearer : 'Bearer ' . $bearer;
        }

        $cacheKey = 'mopay.access_token.' . $this->projectSlug();
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['expires_at'])) {
            if (time() < ((int) $cached['expires_at'] - 60)) {
                return 'Bearer ' . (string) $cached['access_token'];
            }
        }

        $authKey = trim((string) ($this->config['auth_key'] ?? ''));
        if ($authKey === '') {
            throw new \RuntimeException('Missing MOPAY_AUTH_KEY.');
        }

        $token = $this->fetchAccessToken($authKey);
        if ($token !== null) {
            return 'Bearer ' . $token;
        }

        // Critical: parrot_mis uses the auth_key value as-is (often raw base64, not "Basic …").
        return $authKey;
    }

    protected function fetchAccessToken(string $authKey): ?string
    {
        $basicCredential = $authKey;
        if (stripos($basicCredential, 'basic ') === 0) {
            $basicCredential = trim(substr($basicCredential, 6));
        }

        $serverBase = $this->serverBaseUrl();
        $tokenUrls = [];

        $explicit = trim((string) ($this->config['token_url'] ?? ''));
        if ($explicit !== '') {
            $tokenUrls[] = $explicit;
        }
        if ($serverBase !== '') {
            $tokenUrls[] = $serverBase . '/token';
            $tokenUrls[] = preg_replace('#^http://#', 'https://', $serverBase) . '/token';
        }
        $tokenUrls[] = 'https://preproduction-gateway.bizao.com/token';
        $tokenUrls = array_values(array_unique(array_filter($tokenUrls)));

        $headers = [
            'Authorization' => 'Basic ' . $basicCredential,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];

        foreach ($tokenUrls as $tokenUrl) {
            try {
                $res = Http::withHeaders($headers)
                    ->asForm()
                    ->timeout(20)
                    ->post($tokenUrl, ['grant_type' => 'client_credentials']);

                if (!$res->successful() || !$res->json('access_token')) {
                    Log::debug('MoPay token fetch skipped', [
                        'project' => $this->projectSlug(),
                        'url' => $tokenUrl,
                        'http' => $res->status(),
                    ]);
                    continue;
                }

                $accessToken = (string) $res->json('access_token');
                $expiresIn = (int) ($res->json('expires_in') ?? 3600);
                $expiresAt = time() + max(60, $expiresIn);

                Cache::put('mopay.access_token.' . $this->projectSlug(), [
                    'access_token' => $accessToken,
                    'expires_at' => $expiresAt,
                    'token_url' => $tokenUrl,
                ], max(60, $expiresIn));

                return $accessToken;
            } catch (\Throwable $e) {
                Log::warning('MoPay token fetch failed', [
                    'project' => $this->projectSlug(),
                    'url' => $tokenUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function defaultApiHeaders(bool $withBizaoCategory = true): array
    {
        $headers = [
            'Authorization' => $this->authorizationHeader(),
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
        ];

        if ($withBizaoCategory) {
            $headers['category'] = (string) ($this->config['category'] ?? 'BIZAO');
        }

        return $headers;
    }

    /**
     * Collect money from a customer MoMo wallet (payment+transfer or debit-only).
     *
     * @param  array{
     *   account_no: string,
     *   amount: int,
     *   transaction_id: string,
     *   title?: string,
     *   details?: string,
     *   message?: string,
     *   transfer_message?: string,
     *   receiver_account_no?: string|null,
     *   currency?: string,
     *   country_code?: string,
     *   mno?: string,
     *   use_transfer?: bool
     * }  $input
     * @return array{ok:bool,http_status:int,flow:string,url:string,request:array,response:mixed,raw:string,auth_mode:string}
     */
    public function initiateCollection(array $input): array
    {
        $amount = (int) ($input['amount'] ?? 0);
        $transactionId = (string) ($input['transaction_id'] ?? '');
        $accountNo = $this->normalizeMsisdn((string) ($input['account_no'] ?? ''));
        $currency = (string) ($input['currency'] ?? $this->config['default_currency'] ?? 'RWF');
        $country = strtolower((string) ($input['country_code'] ?? $this->config['default_country_code'] ?? 'rw'));
        $mno = (string) ($input['mno'] ?? $this->config['default_mno'] ?? 'mtn');
        $receiver = trim((string) ($input['receiver_account_no'] ?? $this->config['receiver_account_no'] ?? ''));
        $useTransfer = ($input['use_transfer'] ?? true) && $receiver !== '';

        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($this->config['message_prefix'] ?? 'MOPAY')) ?: 'MOPAY';
        $message = (string) ($input['message'] ?? ($prefix . '_PAYMENT'));
        $transferMessage = (string) ($input['transfer_message'] ?? ($prefix . '_RECEIVER_TRANSFER'));

        $base = $this->serverBaseUrl();
        $url = $useTransfer ? $base . '/api/v1/payment' : $base . '/api/v2/momo/debit';

        if ($useTransfer) {
            $receiverMsisdn = $this->normalizeMsisdn($receiver);
            $payload = [
                'transactionId' => $transactionId,
                'account_no' => $accountNo,
                'title' => (string) ($input['title'] ?? $this->config['payment_title'] ?? 'Service Payment'),
                'details' => (string) ($input['details'] ?? $this->config['payment_details'] ?? 'Authorized customer payment'),
                'payment_type' => 'momo',
                'amount' => $amount,
                'currency' => $currency,
                'message' => $message,
                'transfers' => [[
                    'transactionId' => $transactionId . '_T',
                    'account_no' => $receiverMsisdn,
                    'payment_type' => 'momo',
                    'amount' => $amount,
                    'currency' => $currency,
                    'message' => $transferMessage,
                ]],
            ];
            $flow = 'payment_with_transfer';
        } else {
            $payload = [
                'account_no' => $accountNo,
                'payment_type' => 'momo',
                'message' => $message,
                'transactionId' => $transactionId,
                'currency' => $currency,
                'amount' => $amount,
                'country_code' => $country,
                'mno' => $mno,
            ];
            $flow = 'debit_only';
        }

        $authValue = $this->authorizationHeader();
        $headers = [
            'Authorization' => $authValue,
            'Content-Type' => 'application/json; charset=UTF-8',
            'Accept' => 'application/json',
            'category' => (string) ($this->config['category'] ?? 'BIZAO'),
        ];

        $res = Http::withHeaders($headers)->timeout(60)->post($url, $payload);
        $body = $res->json();
        $raw = $res->body();

        // parrot_mis start.php: retry once if transfer amount mismatch; enable allow_transfer_cap.
        if (
            $useTransfer
            && $res->status() >= 400
            && is_array($body)
            && isset($body['message'])
            && is_string($body['message'])
            && stripos($body['message'], 'Total Transfer amount not match with paid amount') !== false
        ) {
            Http::withHeaders($headers)->timeout(30)->post($base . '/api/v1/user/settings', [
                'id' => 'allow_transfer_cap',
                'value' => true,
            ]);

            $res = Http::withHeaders($headers)->timeout(60)->post($url, $payload);
            $body = $res->json();
            $raw = $res->body();
        }

        // Duplicate transaction id → mint a new id and retry once.
        if ($res->status() >= 400 && $this->isDuplicateTransactionError($body ?? $raw)) {
            $transactionId = $this->newTransactionId(preg_replace('/_[0-9].*$/', '', $transactionId) ?: 'FRW');
            if (isset($payload['transactionId'])) {
                $payload['transactionId'] = $transactionId;
            }
            if (isset($payload['transfers'][0]['transactionId'])) {
                $payload['transfers'][0]['transactionId'] = $transactionId . '_T';
            }
            $res = Http::withHeaders($headers)->timeout(60)->post($url, $payload);
            $body = $res->json();
            $raw = $res->body();
        }

        // Receiver MSISDN not authorized on merchant → try env receiver, then debit-only.
        $errMsg = is_array($body) && isset($body['message']) && is_string($body['message'])
            ? $body['message']
            : (string) $raw;
        if (
            $useTransfer
            && $res->status() >= 400
            && stripos($errMsg, 'TARGET_AUTHORIZATION') !== false
        ) {
            $envReceiver = trim((string) ($this->config['receiver_account_no'] ?? ''));
            $triedReceiver = $this->normalizeMsisdn($receiver);
            $envMsisdn = $envReceiver !== '' ? $this->normalizeMsisdn($envReceiver) : '';

            if ($envMsisdn !== '' && $envMsisdn !== $triedReceiver) {
                $retryPayload = $payload;
                $retryPayload['transfers'][0]['account_no'] = $envMsisdn;
                $res = Http::withHeaders($headers)->timeout(60)->post($url, $retryPayload);
                $body = $res->json();
                $raw = $res->body();
                $payload = $retryPayload;
                $errMsg = is_array($body) && isset($body['message']) && is_string($body['message'])
                    ? $body['message']
                    : (string) $raw;
            }

            if ($res->status() >= 400 && stripos($errMsg, 'TARGET_AUTHORIZATION') !== false) {
                $debitUrl = $base . '/api/v2/momo/debit';
                $debitPayload = [
                    'account_no' => $accountNo,
                    'payment_type' => 'momo',
                    'message' => $message,
                    'transactionId' => $transactionId,
                    'currency' => $currency,
                    'amount' => $amount,
                    'country_code' => $country,
                    'mno' => $mno,
                ];
                $res = Http::withHeaders($headers)->timeout(60)->post($debitUrl, $debitPayload);
                $body = $res->json();
                $raw = $res->body();
                $payload = $debitPayload;
                $url = $debitUrl;
                $flow = 'debit_only_after_target_auth_error';
            }
        }

        return [
            'ok' => $res->successful(),
            'http_status' => $res->status(),
            'flow' => $flow,
            'url' => $url,
            'request' => $payload,
            'response' => $body,
            'raw' => $raw,
            'auth_mode' => $this->describeAuthMode($authValue),
            'msisdn' => $accountNo,
            'transaction_id' => (string) ($payload['transactionId'] ?? $transactionId),
            'error_message' => $res->successful()
                ? null
                : $this->humanizeError(is_array($body) ? $body : $raw),
        ];
    }

    /**
     * True only when MoPay reports the MoMo debit as fully settled (PIN approved).
     *
     * MoPay uses statusDesc: PENDING (prompt open) vs SUCCESSFUL (money moved).
     * Never treat HTTP/statusCode 200 or momoRef alone as paid.
     *
     * @param  bool  $allowNumericHttpSuccess  Legacy webhook payloads without statusDesc.
     */
    public function isSettledSuccess(mixed $body, bool $allowNumericHttpSuccess = false): bool
    {
        if (!is_array($body) || $body === []) {
            return false;
        }

        // MoPay Gateway V1: statusDesc is the authoritative settlement field.
        $desc = strtolower(trim((string) ($body['statusDesc'] ?? $body['status_desc'] ?? '')));
        if ($desc !== '') {
            if ($this->isPendingStatus($desc) || $this->isFailedStatus($desc)) {
                return false;
            }
            if (in_array($desc, ['success', 'successful', 'succeeded', 'completed', 'paid', 'approved'], true)) {
                return true;
            }
            // e.g. TARGET_AUTHORIZATION_ERROR — not settled.
            return false;
        }

        $values = $this->extractStatusValues($body);

        foreach ($values as $value) {
            $s = strtolower(trim((string) $value));
            if ($s === '') {
                continue;
            }
            if ($this->isPendingStatus($s) || $this->isFailedStatus($s)) {
                return false;
            }
        }

        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                // Only exact HTTP 200 (not 201 PENDING) and only for legacy webhooks.
                if ($allowNumericHttpSuccess && (int) $value === 200) {
                    return true;
                }
                continue;
            }
            $s = strtolower(trim((string) $value));
            if (in_array($s, ['success', 'successful', 'succeeded', 'completed', 'paid', 'approved'], true)) {
                return true;
            }
        }

        if (array_key_exists('resultCode', $body) && (string) $body['resultCode'] === '0') {
            return !$this->hasPendingOrFailedHint($values);
        }

        return false;
    }

    /** Explicit failure / cancel / timeout for webhook handling. */
    public function isSettledFailure(mixed $body): bool
    {
        if (!is_array($body) || $body === []) {
            return false;
        }

        $desc = strtolower(trim((string) ($body['statusDesc'] ?? $body['status_desc'] ?? '')));
        if ($desc !== '') {
            if ($this->isPendingStatus($desc)) {
                return false;
            }
            if ($this->isFailedStatus($desc) || str_contains($desc, 'target_authorization') || str_contains($desc, 'error')) {
                return true;
            }
        }

        foreach ($this->extractStatusValues($body) as $value) {
            $s = strtolower(trim((string) $value));
            if ($s !== '' && $this->isFailedStatus($s)) {
                return true;
            }
            // MoPay uses numeric status 400/500 with empty statusDesc on some failures.
            if ((is_int($value) || (is_string($value) && ctype_digit(trim($value)))) && (int) $value >= 400) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<mixed>
     */
    protected function extractStatusValues(array $body): array
    {
        $keys = ['statusDesc', 'status_desc', 'status', 'transactionStatus', 'state', 'payment_status', 'txnStatus', 'momoStatus'];
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null && $body[$key] !== '') {
                $values[] = $body[$key];
            }
        }
        if (isset($body['data']) && is_array($body['data'])) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $body['data']) && $body['data'][$key] !== null && $body['data'][$key] !== '') {
                    $values[] = $body['data'][$key];
                }
            }
        }

        return $values;
    }

    /** @param  list<mixed>  $values */
    protected function hasPendingOrFailedHint(array $values): bool
    {
        foreach ($values as $value) {
            $s = strtolower(trim((string) $value));
            if ($s !== '' && ($this->isPendingStatus($s) || $this->isFailedStatus($s))) {
                return true;
            }
        }

        return false;
    }

    protected function isPendingStatus(string $s): bool
    {
        foreach (['pending', 'processing', 'initiated', 'queued', 'waiting', 'inprogress', 'in_progress', 'submitted', 'ongoing'] as $hint) {
            if ($s === $hint || str_contains($s, $hint)) {
                return true;
            }
        }

        return false;
    }

    protected function isFailedStatus(string $s): bool
    {
        foreach (['fail', 'reject', 'cancel', 'timeout', 'expired', 'declined', 'error'] as $hint) {
            if ($s === $hint || str_contains($s, $hint)) {
                // Avoid matching "successful" via "fail" — not needed; "successful" has no fail hint.
                if (str_contains($s, 'success')) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * GET /api/v1/momo/transactionstatus/{trxId}
     *
     * @return array{ok:bool,http_status:int,response:mixed,raw:string,success:bool}
     */
    public function transactionStatus(string $transactionId): array
    {
        $trxId = preg_replace('/_T$/', '', trim($transactionId)) ?: trim($transactionId);
        $url = $this->serverBaseUrl() . '/api/v1/momo/transactionstatus/' . rawurlencode($trxId);
        $headers = [
            'Authorization' => $this->authorizationHeader(),
            'Accept' => 'application/json',
        ];

        $res = Http::withHeaders($headers)->timeout(30)->get($url);
        $body = $res->json();
        $raw = $res->body();

        // Only PIN-approved / settled statuses count — never HTTP/statusCode 200 alone.
        $success = $res->successful() && $this->isSettledSuccess(is_array($body) ? $body : null);
        $failed = $res->successful() && !$success && $this->isSettledFailure(is_array($body) ? $body : null);

        if ($res->successful() && is_array($body) && !$success && !$failed) {
            Log::info('MoPay transaction still unsettled (awaiting PIN or final status)', [
                'project' => $this->projectSlug(),
                'transaction_id' => $trxId,
                'status' => $body['status'] ?? null,
                'statusDesc' => $body['statusDesc'] ?? null,
                'statusCode' => $body['statusCode'] ?? null,
            ]);
        }

        return [
            'ok' => $res->successful(),
            'http_status' => $res->status(),
            'response' => $body,
            'raw' => $raw,
            'success' => $success,
            'failed' => $failed,
            'error_message' => ($failed || (!$res->successful() && is_array($body)))
                ? $this->humanizeError($body)
                : null,
        ];
    }

    /**
     * Build a unique MoPay transaction id (avoids "Trx id already exists").
     */
    public function newTransactionId(string $prefix): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $prefix) ?: 'FRW');
        $id = $clean . '_' . time() . '_' . substr((string) microtime(true), -6) . '_' . random_int(100000, 999999);

        return preg_replace('/[^A-Z0-9_]/', '', $id) ?: ('FRW_' . time() . '_' . random_int(100000, 999999));
    }

    /**
     * Pull the best raw error/status text from a MoPay body or webhook payload.
     */
    public function extractErrorText(mixed $body): string
    {
        if (is_string($body) && trim($body) !== '') {
            return trim($body);
        }
        if (!is_array($body)) {
            return '';
        }

        $keys = [
            'message', 'error', 'error_message', 'errorMessage', 'reason', 'statusMessage',
            'status_message', 'description', 'detail', 'details', 'failureReason', 'failure_reason',
        ];
        foreach ($keys as $key) {
            if (!empty($body[$key]) && (is_string($body[$key]) || is_numeric($body[$key]))) {
                $text = trim((string) $body[$key]);
                if ($text !== '' && !ctype_digit($text)) {
                    return $text;
                }
            }
        }
        if (isset($body['data']) && is_array($body['data'])) {
            $nested = $this->extractErrorText($body['data']);
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }

    /**
     * Map MoPay/MoMo gateway errors to clear payer-facing messages.
     */
    public function humanizeError(mixed $bodyOrText, string $fallback = 'Mobile Money payment failed. Please try again.'): string
    {
        $raw = is_string($bodyOrText) ? trim($bodyOrText) : $this->extractErrorText($bodyOrText);
        $lower = strtolower($raw);

        if ($raw === '') {
            return $fallback;
        }

        $map = [
            ['insufficient', 'Insufficient balance on the Mobile Money account.'],
            ['not enough', 'Insufficient balance on the Mobile Money account.'],
            ['not_enough', 'Insufficient balance on the Mobile Money account.'],
            ['low balance', 'Insufficient balance on the Mobile Money account.'],
            ['nofund', 'Insufficient balance on the Mobile Money account.'],
            ['no fund', 'Insufficient balance on the Mobile Money account.'],
            ['already exists', 'A previous payment request is still open. Wait a moment, then try again.'],
            ['trx id', 'A previous payment request is still open. Wait a moment, then try again.'],
            ['transaction id already', 'A previous payment request is still open. Wait a moment, then try again.'],
            ['wrong pin', 'Incorrect Mobile Money PIN. Please try again.'],
            ['invalid pin', 'Incorrect Mobile Money PIN. Please try again.'],
            ['incorrect pin', 'Incorrect Mobile Money PIN. Please try again.'],
            ['pin', null], // handled carefully below
            ['timeout', 'Payment timed out before approval. Please try again.'],
            ['timed out', 'Payment timed out before approval. Please try again.'],
            ['expired', 'Payment request expired. Please try again.'],
            ['cancel', 'Payment was cancelled on the phone.'],
            ['reject', 'Payment was rejected on the phone.'],
            ['declined', 'Payment was declined.'],
            ['target_authorization', 'The receive Mobile Money number is not authorized on MoPay. Update Settings → Payments.'],
            ['target authorization', 'The receive Mobile Money number is not authorized on MoPay. Update Settings → Payments.'],
            ['not authorized', 'This Mobile Money number is not authorized for this payment.'],
            ['authentication', 'Mobile Money authentication failed. Please try again shortly.'],
            ['ip address', 'Payment gateway IP authorization failed. Contact support.'],
            ['subscriber', 'Mobile Money subscriber issue. Check the phone number and try again.'],
            ['msisdn', 'Invalid Mobile Money number. Check and try again.'],
            ['invalid phone', 'Invalid Mobile Money number. Check and try again.'],
            ['invalid number', 'Invalid Mobile Money number. Check and try again.'],
            ['user limit', 'Too many payment attempts. Wait a few minutes and try again.'],
            ['limit exceed', 'Mobile Money limit exceeded for this account.'],
            ['daily limit', 'Daily Mobile Money limit reached. Try a smaller amount or wait.'],
            ['service unavailable', 'Mobile Money service is temporarily unavailable. Try again shortly.'],
            ['internal', 'Mobile Money gateway error. Please try again shortly.'],
        ];

        foreach ($map as [$needle, $friendly]) {
            if ($friendly === null) {
                continue;
            }
            if ($needle !== '' && str_contains($lower, $needle)) {
                return $friendly;
            }
        }

        // PIN failures without matching "wrong/invalid" above (avoid matching unrelated words).
        if (preg_match('/\bpin\b/i', $raw) && preg_match('/\b(wrong|invalid|incorrect|fail|error)\b/i', $raw)) {
            return 'Incorrect Mobile Money PIN. Please try again.';
        }

        // Keep readable gateway text when it already looks user-facing.
        if (strlen($raw) <= 180 && !str_contains($lower, '{') && !str_starts_with($lower, 'curl ')) {
            // Prefer sentence case without dumping raw technical codes alone.
            if (preg_match('/^[A-Z0-9_]{6,}$/', $raw)) {
                return $fallback;
            }

            return $raw;
        }

        return $fallback;
    }

    /**
     * True when gateway text indicates a duplicate transaction id (safe to retry with a new id).
     */
    public function isDuplicateTransactionError(mixed $bodyOrText): bool
    {
        $raw = strtolower(is_string($bodyOrText) ? $bodyOrText : $this->extractErrorText($bodyOrText));

        return $raw !== '' && (
            str_contains($raw, 'already exists')
            || str_contains($raw, 'trx id already')
            || str_contains($raw, 'transaction id already')
            || str_contains($raw, 'duplicate')
        );
    }

    /**
     * Register this project's webhook URL + signing key on the MoPay merchant settings.
     * Warning: MoPay stores one callback_url per merchant — use a dedicated merchant per project.
     *
     * @return array{ok:bool,callback_url:string,callback_url_http:int,callback_url_body:string,signing_key_http:int,signing_key_body:string}
     */
    public function registerCallbackSettings(?string $callbackUrl = null): array
    {
        $secret = (string) ($this->config['callback_signing_key'] ?? '');
        if ($secret === '') {
            return [
                'ok' => false,
                'callback_url' => '',
                'callback_url_http' => 0,
                'callback_url_body' => 'MOPAY_CALLBACK_SIGNING_KEY is empty.',
                'signing_key_http' => 0,
                'signing_key_body' => '',
            ];
        }

        $callbackUrl = $callbackUrl
            ?: (string) ($this->config['callback_url'] ?? '');

        $settingsUrl = $this->serverBaseUrl() . '/api/v1/user/settings';
        $headers = $this->defaultApiHeaders(false);

        $urlRes = Http::withHeaders($headers)->timeout(30)->post($settingsUrl, [
            'id' => 'callback_url',
            'value' => $callbackUrl,
        ]);

        $keyRes = Http::withHeaders($headers)->timeout(30)->post($settingsUrl, [
            'id' => 'callback_signing_key',
            'value' => $secret,
        ]);

        return [
            'ok' => $urlRes->successful() && $keyRes->successful(),
            'callback_url' => $callbackUrl,
            'callback_url_http' => $urlRes->status(),
            'callback_url_body' => $urlRes->body(),
            'signing_key_http' => $keyRes->status(),
            'signing_key_body' => $keyRes->body(),
        ];
    }

    public function describeAuthMode(string $authValue): string
    {
        if (stripos($authValue, 'bearer ') === 0) {
            return 'bearer';
        }
        if (stripos($authValue, 'basic ') === 0) {
            return 'basic_prefix';
        }

        return 'raw_auth_key';
    }
}
