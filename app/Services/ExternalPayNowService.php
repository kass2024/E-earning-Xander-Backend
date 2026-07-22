<?php

namespace App\Services;

use App\Mail\ExternalPayNowReceiptMail;
use App\Models\Course;
use App\Models\ExternalCoursePayment;
use App\Services\Mopay\MopayGatewayClient;
use App\Support\SimpleTextPdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Guest / external MoMo payments for public Pay Now — never touches CourseEnrollment or CoursePayment.
 */
class ExternalPayNowService
{
    private readonly MopayGatewayClient $gateway;

    private readonly MailDeliveryService $mail;

    public function __construct(?MopayGatewayClient $gateway = null, ?MailDeliveryService $mail = null)
    {
        $this->gateway = $gateway ?? new MopayGatewayClient();
        $this->mail = $mail ?? new MailDeliveryService();
    }

    /**
     * @return list<array{id:int,title:string,price:int,currency:string,duration:?string,description:?string,brand_name:string,receiver_phone:?string}>
     */
    public function listPayableCourses(): array
    {
        $currency = strtoupper((string) config('services.mopay.default_currency', 'RWF'));
        $resolver = app(PaymentReceiverService::class);

        return Course::query()
            ->where('price', '>', 0)
            ->whereNull('platform_institution_id')
            ->orderBy('title')
            ->get(['id', 'title', 'price', 'duration', 'description', 'status', 'platform_institution_id'])
            ->map(function (Course $c) use ($currency, $resolver) {
                $brand = $resolver->resolve($c);

                return [
                    'id' => (int) $c->id,
                    'title' => (string) $c->title,
                    'price' => (int) max(0, round((float) $c->price)),
                    'currency' => $currency,
                    'duration' => $c->duration ? (string) $c->duration : null,
                    'description' => $c->description ? mb_substr(strip_tags((string) $c->description), 0, 220) : null,
                    'brand_name' => $brand['brand_name'],
                    'receiver_phone' => $brand['display_momo_phone'] ?: null,
                ];
            })
            ->values()
            ->all();
    }

    public function receiverAccountNo(?Course $course = null): string
    {
        return app(PaymentReceiverService::class)->receiverAccountNo($course);
    }

    /**
     * @return array{ok:bool,status?:int,message?:string,payment?:array,transaction_id?:string}
     */
    public function requestPayment(
        int $courseId,
        int $amountRwf,
        string $phone,
        string $email,
        ?string $payerName = null,
        string $mno = 'mtn'
    ): array {
        if (!$this->gateway->isConfigured()) {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Mobile Money is not configured yet. Please try again later.',
            ];
        }

        $course = Course::find($courseId);
        if (!$course) {
            return ['ok' => false, 'status' => 404, 'message' => 'Course not found.'];
        }

        // Main-platform Pay Now never accepts partner-institution courses.
        if (!empty($course->platform_institution_id)) {
            return ['ok' => false, 'status' => 403, 'message' => 'This course belongs to another institution.'];
        }

        $max = (int) max(0, round((float) ($course->price ?? 0)));
        if ($max <= 0) {
            return ['ok' => false, 'status' => 422, 'message' => 'This course is not payable.'];
        }

        if ($amountRwf < 1) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter an amount of at least 1 RWF.'];
        }
        if ($amountRwf > $max) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => "Amount cannot exceed the course maximum ({$max} RWF).",
                'max_amount' => $max,
            ];
        }

        $brand = app(PaymentReceiverService::class)->resolve($course);
        $receiver = (string) ($brand['receiver_account_no'] ?? '');
        if ($receiver === '') {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Payment receive number is not set. Contact support.',
            ];
        }

        $msisdn = $this->gateway->normalizeMsisdn($phone);
        if (strlen($msisdn) < 12) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter a valid MTN/Airtel Rwanda mobile money number.'];
        }

        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter a valid email for the receipt.'];
        }

        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) config('services.mopay.project_slug', 'FRW')) ?: 'FRW');
        $transactionId = $this->gateway->newTransactionId($slug . 'EXT_' . $course->id);

        $currency = (string) config('services.mopay.default_currency', 'RWF');
        $prefix = (string) config('services.mopay.message_prefix', 'FRWANDA');
        $title = (string) config('services.mopay.payment_title', 'FR_Rwanda_course_payment');

        try {
            $gatewayResult = $this->gateway->initiateCollection([
                'account_no' => $msisdn,
                'amount' => $amountRwf,
                'transaction_id' => $transactionId,
                'title' => $title,
                'details' => 'External Pay Now: ' . ($course->title ?? $course->id),
                'message' => $prefix . '_EXT_PAYMENT',
                'transfer_message' => $prefix . '_EXT_RECEIVER',
                'receiver_account_no' => $receiver,
                'currency' => $currency,
                'mno' => $mno ?: 'mtn',
                'use_transfer' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('External Pay Now MoPay failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'message' => 'Unable to reach Mobile Money gateway.'];
        }

        $httpOk = (bool) ($gatewayResult['ok'] ?? false);
        $body = is_array($gatewayResult['response'] ?? null) ? $gatewayResult['response'] : [];
        $transactionId = (string) ($gatewayResult['transaction_id'] ?? $transactionId);
        $failureReason = $httpOk
            ? null
            : (string) ($gatewayResult['error_message'] ?? $this->gateway->humanizeError($body));

        $payment = ExternalCoursePayment::create([
            'course_id' => $course->id,
            'course_title' => (string) $course->title,
            'course_price_rwf' => $max,
            'amount_rwf' => $amountRwf,
            'currency' => strtoupper($currency),
            'payer_name' => $payerName ? trim($payerName) : null,
            'payer_email' => $email,
            'payer_phone' => $phone,
            'msisdn' => (string) ($gatewayResult['msisdn'] ?? $msisdn),
            'mno' => $mno ?: 'mtn',
            'external_reference' => $transactionId,
            'provider' => 'mopay',
            'status' => $httpOk ? 'processing' : 'failed',
            'metadata' => [
                'flow' => 'external_pay_now',
                'request' => $gatewayResult['request'] ?? null,
                'response' => $body,
                'http_status' => $gatewayResult['http_status'] ?? null,
                'auth_mode' => $gatewayResult['auth_mode'] ?? null,
                'receiver_from_settings' => true,
                'receiver_source' => $brand['source'] ?? 'main',
                'receiver_brand' => $brand['brand_name'] ?? null,
                'receiver_name' => $brand['momo_receiver_name'] ?? null,
                'receiver_phone' => $brand['display_momo_phone'] ?? null,
                'brand_logo_url' => $brand['brand_logo_url'] ?? null,
                'brand_primary_color' => $brand['brand_primary_color'] ?? '#0070D0',
                'platform_institution_id' => $brand['platform_institution_id'] ?? null,
                'failure_reason' => $failureReason,
            ],
        ]);

        if (!$httpOk) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => $failureReason ?: 'Mobile Money request was rejected.',
                'error_code' => is_array($body) ? ($body['status'] ?? $body['code'] ?? null) : null,
                'transaction_id' => $transactionId,
                'payment' => $this->mapPayment($payment),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Approve the payment prompt on your phone. A PDF receipt will be emailed when payment succeeds.',
            'transaction_id' => $transactionId,
            'payment' => $this->mapPayment($payment),
        ];
    }

    public function findByReference(string $transactionId): ?ExternalCoursePayment
    {
        $base = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;

        return ExternalCoursePayment::query()
            ->whereIn('external_reference', array_values(array_unique([$transactionId, $base])))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{ok:bool,message:string,payment?:array}
     */
    public function syncPaymentFromGateway(string $transactionId): array
    {
        $payment = $this->findByReference($transactionId);
        if (!$payment) {
            return ['ok' => false, 'message' => 'Payment not found.'];
        }

        if (in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            $this->ensureReceiptEmailed($payment->fresh());

            return [
                'ok' => true,
                'message' => 'Payment already confirmed.',
                'payment' => $this->mapPayment($payment->fresh()),
            ];
        }

        $gateway = $this->gateway->transactionStatus((string) $payment->external_reference);
        if (!$gateway['success']) {
            $gateway = $this->gateway->transactionStatus((string) $payment->external_reference . '_T');
        }

        if ($gateway['success']) {
            $this->handleWebhookSuccess(
                (string) $payment->external_reference,
                is_array($gateway['response']) ? $gateway['response'] : ['source' => 'status_poll']
            );

            return [
                'ok' => true,
                'message' => 'Payment confirmed. Receipt emailed if possible.',
                'payment' => $this->mapPayment($payment->fresh()),
            ];
        }

        if (!empty($gateway['failed'])) {
            $this->handleWebhookFailure(
                (string) $payment->external_reference,
                is_array($gateway['response']) ? $gateway['response'] : ['source' => 'status_poll'],
                (string) ($gateway['error_message'] ?? '')
            );

            return [
                'ok' => true,
                'message' => (string) ($gateway['error_message'] ?? 'Payment failed.'),
                'payment' => $this->mapPayment($payment->fresh()),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Payment still processing.',
            'payment' => $this->mapPayment($payment),
        ];
    }

    public function handleWebhookSuccess(string $transactionId, array $data): bool
    {
        $payment = $this->findByReference($transactionId);
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

        $this->ensureReceiptEmailed($payment->fresh());

        return true;
    }

    public function handleWebhookFailure(string $transactionId, array $data, ?string $reason = null): bool
    {
        $payment = $this->findByReference($transactionId);
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

    public function ensureReceiptEmailed(ExternalCoursePayment $payment): bool
    {
        if ($payment->receipt_emailed) {
            return true;
        }
        if (!in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            return false;
        }

        $pdf = $this->buildReceiptPdf($payment);
        $filename = 'FR_receipt_' . $payment->external_reference . '.pdf';
        $path = 'external-receipts/' . $filename;
        Storage::disk('local')->put($path, $pdf);

        $payment->receipt_path = $path;
        $payment->save();

        $sent = $this->mail->sendTo(
            $payment->payer_email,
            new ExternalPayNowReceiptMail($payment, $pdf, $filename, $this->receiptBrandContext($payment)),
            ['event' => 'external_pay_now_receipt', 'reference' => $payment->external_reference]
        );

        if ($sent) {
            $payment->receipt_emailed = true;
            $payment->save();
        }

        return $sent;
    }

    public function buildReceiptPdf(ExternalCoursePayment $payment): string
    {
        $brand = $this->receiptBrandContext($payment);
        $brandName = (string) ($brand['brand_name'] ?? 'F&R Rwanda');
        $remaining = max(0, (int) $payment->course_price_rwf - (int) $payment->amount_rwf);
        $statusLabel = $remaining > 0 ? 'PARTIAL PAID' : 'PAID';

        $lines = [
            '',
            $brandName . ' - Payment Receipt',
            '--------------------------------',
            'Reference: ' . $payment->external_reference,
            'Date: ' . optional($payment->paid_at)->format('Y-m-d H:i') . ' UTC',
            'Payer: ' . ($payment->payer_name ?: '-'),
            'Email: ' . $payment->payer_email,
            'Phone: ' . $payment->payer_phone,
            'Course: ' . ($payment->course_title ?: ('#' . $payment->course_id)),
            'Course max: ' . number_format($payment->course_price_rwf) . ' ' . $payment->currency,
            'Amount paid: ' . number_format($payment->amount_rwf) . ' ' . $payment->currency,
        ];

        if ($remaining > 0) {
            $lines[] = 'Remaining due: ' . number_format($remaining) . ' ' . $payment->currency;
        }

        $lines[] = 'Status: ' . $statusLabel;
        $lines[] = 'Provider: Mobile Money (Pay Now)';

        if (!empty($brand['momo_receiver_name']) || !empty($brand['display_momo_phone'])) {
            $lines[] = 'Received by: ' . trim((string) ($brand['momo_receiver_name'] ?? $brandName));
            if (!empty($brand['display_momo_phone'])) {
                $lines[] = 'Owner MoMo: ' . $brand['display_momo_phone'];
            }
        }

        $lines[] = '--------------------------------';
        $lines[] = 'This receipt is not linked to a learner login.';
        $lines[] = 'Thank you for paying with ' . $brandName . '.';

        return SimpleTextPdf::fromLines($lines, $brandName . ' Receipt');
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptBrandContext(ExternalCoursePayment $payment): array
    {
        $meta = is_array($payment->metadata) ? $payment->metadata : [];
        if (!empty($meta['receiver_brand']) || !empty($meta['receiver_phone'])) {
            return [
                'brand_name' => (string) ($meta['receiver_brand'] ?? app(PaymentReceiverService::class)->mainBrandName()),
                'brand_logo_url' => $meta['brand_logo_url'] ?? null,
                'brand_primary_color' => (string) ($meta['brand_primary_color'] ?? '#0070D0'),
                'momo_receiver_name' => (string) ($meta['receiver_name'] ?? ''),
                'display_momo_phone' => (string) ($meta['receiver_phone'] ?? ''),
                'source' => (string) ($meta['receiver_source'] ?? 'main'),
            ];
        }

        $course = Course::query()->find($payment->course_id);

        return app(PaymentReceiverService::class)->resolve($course);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapPayment(ExternalCoursePayment $payment): array
    {
        $meta = is_array($payment->metadata) ? $payment->metadata : [];
        $errorMessage = null;
        if (in_array($payment->status, ['failed', 'cancelled', 'canceled'], true)) {
            $errorMessage = (string) ($meta['failure_reason'] ?? '');
            if ($errorMessage === '') {
                $errorMessage = $this->gateway->humanizeError($meta['webhook'] ?? $meta['response'] ?? null);
            }
        }

        return [
            'id' => $payment->id,
            'course_id' => $payment->course_id,
            'course_title' => $payment->course_title,
            'course_price' => (int) $payment->course_price_rwf,
            'amount' => (int) $payment->amount_rwf,
            'currency' => $payment->currency,
            'payer_name' => $payment->payer_name,
            'payer_email' => $payment->payer_email,
            'payer_phone' => $payment->payer_phone,
            'transaction_id' => $payment->external_reference,
            'status' => $payment->status,
            'receipt_emailed' => (bool) $payment->receipt_emailed,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'error_message' => $errorMessage,
            'external' => true,
        ];
    }
}
