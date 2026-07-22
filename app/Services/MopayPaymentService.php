<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CoursePayment;
use App\Services\Mopay\MopayGatewayClient;
use App\Support\EnrollmentStatusHelper;
use Illuminate\Support\Facades\Log;

/**
 * Xander course enrollment payments on top of the portable MoPay gateway client.
 * Uses this product's own MOPAY_* credentials and single receiver number — do not share with F&R.
 * Gateway HTTP/auth lives in MopayGatewayClient so other projects can reuse it with their own env.
 */
class MopayPaymentService
{
    private readonly MopayGatewayClient $gateway;

    public function __construct(?MopayGatewayClient $gateway = null)
    {
        $this->gateway = $gateway ?? new MopayGatewayClient();
    }

    public function gateway(): MopayGatewayClient
    {
        return $this->gateway;
    }

    public function isConfigured(): bool
    {
        return $this->gateway->isConfigured();
    }

    public function assertReady(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Mobile Money is not configured. Set MOPAY_AUTH_KEY and MOPAY_SERVER_BASE_URL in this project\'s .env.',
            ];
        }

        return ['ok' => true];
    }

    public function courseAmountRwf(Course $course): int
    {
        return (int) max(0, round((float) ($course->price ?? 0)));
    }

    /** Successful MoMo / promo / admin-confirmed payments toward a course enrollment. */
    public function paidTotalRwf(int $courseId, int $studentId): int
    {
        $cents = (int) CoursePayment::query()
            ->where('course_id', $courseId)
            ->where('student_id', $studentId)
            ->whereIn('status', ['paid', 'succeeded', 'completed'])
            ->sum('amount_cents');

        return (int) max(0, (int) round($cents / 100));
    }

    public function remainingDueRwf(Course $course, int $studentId): int
    {
        $due = $this->courseAmountRwf($course);
        if ($due <= 0) {
            return 0;
        }

        return max(0, $due - $this->paidTotalRwf((int) $course->id, $studentId));
    }

    /**
     * @return array{course_price:int,amount_paid:int,amount_remaining:int,currency:string}
     */
    public function balanceFor(Course $course, int $studentId): array
    {
        $price = $this->courseAmountRwf($course);
        $paid = $this->paidTotalRwf((int) $course->id, $studentId);

        return [
            'course_price' => $price,
            'amount_paid' => $paid,
            'amount_remaining' => max(0, $price - $paid),
            'currency' => strtoupper((string) config('services.mopay.default_currency', 'RWF')),
        ];
    }

    /** MoMo receive MSISDN: institution owner when course is institutional, else main SiteSetting / env. */
    public function receiverAccountNo(?Course $course = null): string
    {
        return app(PaymentReceiverService::class)->receiverAccountNo($course);
    }

    public function normalizeMsisdn(string $phone): string
    {
        return $this->gateway->normalizeMsisdn($phone);
    }

    public function authorizationHeader(): string
    {
        return $this->gateway->authorizationHeader();
    }

    public function registerCallbackSettings(?string $callbackUrl = null): array
    {
        $ready = $this->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        $secret = (string) config('services.mopay.callback_signing_key');
        if ($secret === '') {
            return ['ok' => false, 'status' => 500, 'message' => 'MOPAY_CALLBACK_SIGNING_KEY is empty.'];
        }

        $callbackUrl = $callbackUrl
            ?: (string) config('services.mopay.callback_url')
            ?: rtrim((string) config('app.url'), '/') . '/api/admin/payments/mopay/webhook';

        $result = $this->gateway->registerCallbackSettings($callbackUrl);
        if (!$result['ok'] && ($result['callback_url_body'] ?? '') === 'MOPAY_CALLBACK_SIGNING_KEY is empty.') {
            return ['ok' => false, 'status' => 500, 'message' => 'MOPAY_CALLBACK_SIGNING_KEY is empty.'];
        }

        return $result;
    }

    /**
     * @param  int|null  $amountRwf  Optional partial amount (any amount >= 1). Defaults to remaining due.
     */
    public function requestPayment(Course $course, int $studentId, string $phone, string $mno = 'mtn', ?int $amountRwf = null): array
    {
        $ready = $this->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        $enrollment = CourseEnrollment::where('student_id', $studentId)
            ->where('course_id', $course->id)
            ->first();

        if (!$enrollment) {
            return ['ok' => false, 'status' => 422, 'message' => 'You must apply for this course before paying.'];
        }

        if (EnrollmentStatusHelper::isPaid($enrollment->status)) {
            return ['ok' => false, 'status' => 422, 'message' => 'This enrollment is already fully paid.'];
        }

        if (!EnrollmentStatusHelper::canPay($enrollment->status)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Payment is not available for this enrollment status.'];
        }

        $coursePrice = $this->courseAmountRwf($course);
        if ($coursePrice <= 0) {
            return ['ok' => false, 'status' => 422, 'message' => 'Course price is not set for payments.'];
        }

        $balance = $this->balanceFor($course, $studentId);
        $remaining = (int) $balance['amount_remaining'];
        if ($remaining <= 0) {
            $this->markEnrollmentPaidIfSettled((int) $course->id, $studentId);

            return ['ok' => false, 'status' => 422, 'message' => 'No remaining balance for this course.'];
        }

        // Partial payments allowed: any amount from 1 RWF up to remaining due.
        $amount = $amountRwf !== null ? (int) $amountRwf : $remaining;
        if ($amount < 1) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter an amount of at least 1 RWF.'];
        }
        if ($amount > $remaining) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => "Amount cannot exceed the remaining balance ({$remaining} RWF).",
                'amount_remaining' => $remaining,
            ];
        }

        $msisdn = $this->normalizeMsisdn($phone);
        if (strlen($msisdn) < 12) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter a valid MTN/Airtel Rwanda mobile money number.'];
        }

        $receiverPayload = app(PaymentReceiverService::class)->resolve($course);
        $receiver = (string) ($receiverPayload['receiver_account_no'] ?? '');
        if ($receiver === '') {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Payment receive number is not set. Ask an admin to set it under Settings → Payments.',
            ];
        }

        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) config('services.mopay.project_slug', 'FRW')) ?: 'FRW');
        $transactionId = $this->gateway->newTransactionId($slug . '_' . $course->id . '_' . $studentId);

        $currency = (string) config('services.mopay.default_currency', 'RWF');
        $prefix = (string) config('services.mopay.message_prefix', 'FRWANDA');
        $title = (string) config('services.mopay.payment_title', 'FR_Rwanda_course_payment');

        try {
            $gatewayResult = $this->gateway->initiateCollection([
                'account_no' => $msisdn,
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'title' => $title,
                'details' => 'Course: ' . ($course->title ?? $course->id),
                'message' => $prefix . '_COURSE_PAYMENT',
                'transfer_message' => $prefix . '_RECEIVER_TRANSFER',
                'receiver_account_no' => $receiver,
                'currency' => $currency,
                'country_code' => (string) config('services.mopay.default_country_code', 'rw'),
                'mno' => $mno ?: (string) config('services.mopay.default_mno', 'mtn'),
                'use_transfer' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('MoPay request failed', [
                'project' => config('services.mopay.project_slug'),
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 502, 'message' => 'Unable to reach Mobile Money gateway. Try again shortly.'];
        }

        $body = is_array($gatewayResult['response'] ?? null) ? $gatewayResult['response'] : [];
        $httpOk = (bool) ($gatewayResult['ok'] ?? false);
        $msisdnStored = (string) ($gatewayResult['msisdn'] ?? $msisdn);
        $transactionId = (string) ($gatewayResult['transaction_id'] ?? $transactionId);
        $failureReason = $httpOk
            ? null
            : (string) ($gatewayResult['error_message'] ?? $this->gateway->humanizeError($body));

        $payment = CoursePayment::create([
            'course_id' => $course->id,
            'student_id' => $studentId,
            'amount_cents' => $amount * 100,
            'currency' => strtolower($currency),
            'provider' => 'mopay',
            'external_reference' => $transactionId,
            'msisdn' => $msisdnStored,
            'status' => $httpOk ? 'processing' : 'failed',
            'metadata' => [
                'request' => $gatewayResult['request'] ?? null,
                'response' => $body,
                'http_status' => $gatewayResult['http_status'] ?? null,
                'flow' => $gatewayResult['flow'] ?? null,
                'auth_mode' => $gatewayResult['auth_mode'] ?? null,
                'project' => config('services.mopay.project_slug'),
                'partial' => $amount < $remaining,
                'course_price' => $coursePrice,
                'amount_remaining_before' => $remaining,
                'receiver_from_settings' => true,
                'receiver_source' => $receiverPayload['source'] ?? 'main',
                'receiver_brand' => $receiverPayload['brand_name'] ?? null,
                'receiver_name' => $receiverPayload['momo_receiver_name'] ?? null,
                'receiver_phone' => $receiverPayload['display_momo_phone'] ?? null,
                'platform_institution_id' => $receiverPayload['platform_institution_id'] ?? null,
                'failure_reason' => $failureReason,
            ],
        ]);

        if (!$httpOk) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => $failureReason ?: 'Mobile Money request was rejected.',
                'payment_id' => $payment->id,
                'transaction_id' => $transactionId,
            ];
        }

        return [
            'ok' => true,
            'message' => $amount < $remaining
                ? 'Approve the payment prompt on your phone. This is a partial payment toward the course.'
                : 'Approve the payment prompt on your phone to complete enrollment.',
            'payment_id' => $payment->id,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
            'msisdn' => $msisdnStored,
            'course_price' => $coursePrice,
            'amount_paid' => (int) $balance['amount_paid'],
            'amount_remaining' => $remaining,
        ];
    }

    public function markEnrollmentPaid(int $courseId, int $studentId): void
    {
        $enrollment = CourseEnrollment::where('course_id', $courseId)
            ->where('student_id', $studentId)
            ->first();

        if (!$enrollment) {
            return;
        }

        $enrollment->status = 'paid';
        $enrollment->save();
    }

    /**
     * After any successful MoMo payment: activate course.
     * - Fully covered → status paid
     * - Otherwise → status partial_paid (still has access; can pay remaining)
     *
     * @return array{activated:bool,status:?string,amount_paid:int,amount_remaining:int,course_price:int}
     */
    public function applyEnrollmentPaymentProgress(int $courseId, int $studentId): array
    {
        $course = Course::find($courseId);
        $balance = $course
            ? $this->balanceFor($course, $studentId)
            : ['course_price' => 0, 'amount_paid' => 0, 'amount_remaining' => 0];

        $enrollment = CourseEnrollment::where('course_id', $courseId)
            ->where('student_id', $studentId)
            ->first();

        if (!$enrollment) {
            return [
                'activated' => false,
                'status' => null,
                'amount_paid' => (int) $balance['amount_paid'],
                'amount_remaining' => (int) $balance['amount_remaining'],
                'course_price' => (int) $balance['course_price'],
            ];
        }

        $paid = (int) $balance['amount_paid'];
        if ($paid <= 0) {
            return [
                'activated' => EnrollmentStatusHelper::hasCourseAccess($enrollment->status),
                'status' => $enrollment->status,
                'amount_paid' => 0,
                'amount_remaining' => (int) $balance['amount_remaining'],
                'course_price' => (int) $balance['course_price'],
            ];
        }

        $remaining = (int) $balance['amount_remaining'];
        $enrollment->status = $remaining <= 0 ? 'paid' : 'partial_paid';
        $enrollment->save();

        return [
            'activated' => true,
            'status' => $enrollment->status,
            'amount_paid' => $paid,
            'amount_remaining' => $remaining,
            'course_price' => (int) $balance['course_price'],
        ];
    }

    /** @deprecated Use applyEnrollmentPaymentProgress — kept for callers that expect bool. */
    public function markEnrollmentPaidIfSettled(int $courseId, int $studentId): bool
    {
        $result = $this->applyEnrollmentPaymentProgress($courseId, $studentId);

        return !empty($result['activated']);
    }

    /**
     * Poll MoPay transaction status and activate enrollment when gateway reports success.
     * Used when webhooks are delayed/missing.
     *
     * @return array{ok:bool,message:string,payment?:array,enrollment?:array}
     */
    public function syncPaymentFromGateway(string $transactionId): array
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
        $payment = CoursePayment::query()
            ->whereIn('external_reference', array_values(array_unique([$transactionId, $baseRef])))
            ->orderByDesc('id')
            ->first();

        if (!$payment) {
            return ['ok' => false, 'message' => 'Payment not found.'];
        }

        if (in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            $progress = $this->applyEnrollmentPaymentProgress((int) $payment->course_id, (int) $payment->student_id);

            return [
                'ok' => true,
                'message' => 'Payment already confirmed.',
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'transaction_id' => $payment->external_reference,
                    'amount' => (int) round($payment->amount_cents / 100),
                ],
                'enrollment' => $progress,
            ];
        }

        $gateway = $this->gateway->transactionStatus((string) $payment->external_reference);
        if (!$gateway['success']) {
            // Also try transfer leg id.
            $gateway = $this->gateway->transactionStatus((string) $payment->external_reference . '_T');
        }

        if ($gateway['success']) {
            $this->handleWebhookSuccess(
                (string) $payment->external_reference,
                is_array($gateway['response']) ? $gateway['response'] : ['source' => 'status_poll']
            );
            $payment = $payment->fresh();
            $progress = $this->applyEnrollmentPaymentProgress((int) $payment->course_id, (int) $payment->student_id);

            return [
                'ok' => true,
                'message' => 'Payment confirmed and course activated.',
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'transaction_id' => $payment->external_reference,
                    'amount' => (int) round($payment->amount_cents / 100),
                    'error_message' => null,
                ],
                'enrollment' => $progress,
            ];
        }

        if (!empty($gateway['failed'])) {
            $this->handleWebhookFailure(
                (string) $payment->external_reference,
                is_array($gateway['response']) ? $gateway['response'] : ['source' => 'status_poll'],
                (string) ($gateway['error_message'] ?? '')
            );
            $payment = $payment->fresh();
            $meta = is_array($payment->metadata) ? $payment->metadata : [];

            return [
                'ok' => true,
                'message' => (string) ($meta['failure_reason'] ?? $gateway['error_message'] ?? 'Payment failed.'),
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'transaction_id' => $payment->external_reference,
                    'amount' => (int) round($payment->amount_cents / 100),
                    'error_message' => (string) ($meta['failure_reason'] ?? $gateway['error_message'] ?? 'Payment failed.'),
                ],
            ];
        }

        $meta = is_array($payment->metadata) ? $payment->metadata : [];

        return [
            'ok' => true,
            'message' => 'Payment still processing. Approve the prompt on your phone if asked.',
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'transaction_id' => $payment->external_reference,
                'amount' => (int) round($payment->amount_cents / 100),
                'error_message' => $meta['failure_reason'] ?? null,
            ],
            'gateway' => [
                'http_status' => $gateway['http_status'],
                'response' => $gateway['response'],
            ],
        ];
    }

    public function verifyWebhookJwt(string $jwt): array
    {
        $secret = (string) config('services.mopay.callback_signing_key');
        if ($secret === '') {
            throw new \RuntimeException('Webhook secret not configured');
        }

        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        $given = $this->base64UrlDecode($signatureB64);

        if (!hash_equals($expected, $given)) {
            throw new \InvalidArgumentException('JWT signature verification failed');
        }

        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('JWT payload is not valid JSON');
        }

        return $payload;
    }

    private function base64UrlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = (4 - (strlen($b64) % 4)) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', $pad);
        }
        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url string');
        }

        return $decoded;
    }

    public function handleWebhookSuccess(string $transactionId, array $data): bool
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
        $payment = CoursePayment::query()
            ->whereIn('external_reference', array_values(array_unique([$transactionId, $baseRef])))
            ->orderByDesc('id')
            ->first();

        if (!$payment) {
            return false;
        }

        if (!in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            $payment->status = 'paid';
            $payment->paid_at = now();
            $meta = $payment->metadata ?? [];
            $meta['webhook'] = $data;
            unset($meta['failure_reason']);
            $payment->metadata = $meta;
            $payment->save();
        }

        // Activate on any successful payment; partial_paid if balance remains.
        $this->applyEnrollmentPaymentProgress((int) $payment->course_id, (int) $payment->student_id);

        return true;
    }

    public function handleWebhookFailure(string $transactionId, array $data, ?string $reason = null): bool
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
        $payment = CoursePayment::query()
            ->whereIn('external_reference', array_values(array_unique([$transactionId, $baseRef])))
            ->orderByDesc('id')
            ->first();

        if (!$payment) {
            return false;
        }
        if (in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            return false;
        }

        $friendly = $reason !== null && trim($reason) !== ''
            ? trim($reason)
            : $this->gateway->humanizeError($data);

        $payment->status = 'failed';
        $meta = is_array($payment->metadata) ? $payment->metadata : [];
        $meta['webhook'] = $data;
        $meta['failure_reason'] = $friendly;
        $meta['raw_failure'] = $this->gateway->extractErrorText($data);
        $payment->metadata = $meta;
        $payment->save();

        return true;
    }
}
