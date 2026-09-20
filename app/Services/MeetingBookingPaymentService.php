<?php

namespace App\Services;

use App\Jobs\ProvisionMeetingRegistrationJob;
use App\Models\MeetingPayment;
use App\Models\MeetingRegistration;
use App\Models\SiteSetting;
use App\Services\LiveUsdRwfRateService;
use App\Services\Mopay\MopayGatewayClient;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Stripe;

/**
 * Paid meeting bookings: Stripe Checkout + MoPay Mobile Money (same gateway as course payments).
 * Uses Xander Stripe keys from config/services.php (.env STRIPE_*).
 */
class MeetingBookingPaymentService
{
    public function __construct(
        private readonly ?MopayGatewayClient $gateway = null,
        private readonly ?StripePaymentService $stripe = null,
    ) {
    }

    private function mopay(): MopayGatewayClient
    {
        return $this->gateway ?? new MopayGatewayClient();
    }

    private function stripeService(): StripePaymentService
    {
        return $this->stripe ?? app(StripePaymentService::class);
    }

    /** @return array{required:bool,fee_usd:float,fee_rwf:int,stripe_configured:bool,mopay_configured:bool} */
    public function publicConfig(): array
    {
        $settings = SiteSetting::current();
        $required = $this->paymentRequired($settings);
        $feeUsd = $this->feeUsd($settings);
        $quote = $this->forex()->quote();
        $feeRwf = $this->feeRwf($settings, $quote);
        $receiver = app(PaymentReceiverService::class)->resolve(null);

        return [
            'required' => $required,
            'fee_usd' => $feeUsd,
            'fee_rwf' => $feeRwf,
            'stripe_configured' => $this->stripeService()->isConfigured(),
            'mopay_configured' => $this->mopay()->isConfigured() && !empty($receiver['receiver_account_no']),
        ];
    }

    private function forex(): LiveUsdRwfRateService
    {
        return app(LiveUsdRwfRateService::class);
    }

    public function paymentRequired(?SiteSetting $settings = null): bool
    {
        $settings = $settings ?? SiteSetting::current();
        if (Schema::hasColumn('site_settings', 'meeting_payment_required')) {
            return (bool) ($settings->meeting_payment_required ?? true);
        }

        return true;
    }

    public function feeUsd(?SiteSetting $settings = null): float
    {
        $settings = $settings ?? SiteSetting::current();
        if (Schema::hasColumn('site_settings', 'meeting_fee_usd') && $settings->meeting_fee_usd !== null) {
            return max(0, (float) $settings->meeting_fee_usd);
        }

        return max(0, (float) config('services.meeting_booking.fee_usd', 10));
    }

    public function feeRwf(?SiteSetting $settings = null, ?array $quote = null): int
    {
        $usd = $this->feeUsd($settings);
        if ($usd <= 0) {
            return 0;
        }
        $rate = (float) (($quote['rate'] ?? 0) ?: $this->forex()->quote()['rate']);

        return max(1, (int) round($usd * $rate));
    }

    public function registrationIsPaid(MeetingRegistration $registration): bool
    {
        $status = strtolower((string) ($registration->status ?? ''));
        if ($status === 'approved' && strtolower((string) ($registration->payment_status ?? '')) === 'paid') {
            return true;
        }

        if (strtolower((string) ($registration->payment_status ?? '')) === 'paid') {
            return true;
        }

        if (!$this->paymentRequired()) {
            return true;
        }

        return MeetingPayment::query()
            ->where('meeting_registration_id', $registration->id)
            ->whereIn('status', ['paid', 'succeeded', 'completed'])
            ->exists();
    }

    /**
     * Zoom, Daily, and the "appointment is confirmed" email must wait until the booking is paid
     * whenever meeting payment is required.
     */
    public function canFulfillBooking(MeetingRegistration $registration): bool
    {
        return $this->registrationIsPaid($registration);
    }

    /**
     * Mark registration paid and provision the meeting (Zoom/Daily + email).
     */
    public function activateAfterPayment(MeetingRegistration $registration, string $provider, ?string $reference = null): void
    {
        $alreadyPaid = strtolower((string) ($registration->payment_status ?? '')) === 'paid'
            && strtolower((string) ($registration->status ?? '')) === 'approved';

        if (Schema::hasColumn('meeting_registrations', 'payment_status')) {
            $registration->payment_status = 'paid';
        }
        if (Schema::hasColumn('meeting_registrations', 'payment_provider')) {
            $registration->payment_provider = $provider;
        }
        if (Schema::hasColumn('meeting_registrations', 'payment_reference') && $reference) {
            $registration->payment_reference = $reference;
        }
        if (Schema::hasColumn('meeting_registrations', 'paid_at')) {
            $registration->paid_at = $registration->paid_at ?: now();
        }
        if (Schema::hasColumn('meeting_registrations', 'status')) {
            $registration->status = 'Approved';
        }
        $registration->save();

        if ($alreadyPaid) {
            return;
        }

        ProvisionMeetingRegistrationJob::dispatch(
            $registration->id,
            $registration->schedule_label
        )->afterResponse();
    }

    public function createStripeCheckout(MeetingRegistration $registration): array
    {
        $ready = $this->stripeService()->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        if ($this->registrationIsPaid($registration)) {
            return ['ok' => false, 'status' => 422, 'message' => 'This booking is already paid.'];
        }

        $feeUsd = $this->feeUsd();
        $amountCents = (int) round($feeUsd * 100);
        if ($amountCents < 50) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Meeting booking fee is not configured. Set it under Settings → Payments.',
            ];
        }

        $frontend = FrontendUrl::base();
        Stripe::setApiKey(config('services.stripe.secret'));

        $session = StripeCheckoutSession::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Meeting booking — ' . ($registration->full_name ?: 'Guest'),
                        'description' => $registration->schedule_label ?: 'Booked meeting session',
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]],
            'customer_email' => $registration->email,
            'metadata' => [
                'type' => 'meeting_booking',
                'meeting_registration_id' => (string) $registration->id,
            ],
            'success_url' => $frontend . '/meeting-registration?payment=stripe&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontend . '/meeting-registration?payment=cancelled',
        ]);

        MeetingPayment::updateOrCreate(
            [
                'meeting_registration_id' => $registration->id,
                'stripe_session_id' => $session->id,
            ],
            [
                'amount_cents' => $amountCents,
                'currency' => 'usd',
                'provider' => 'stripe',
                'status' => 'pending',
                'metadata' => [
                    'checkout_url' => $session->url,
                    'email' => $registration->email,
                ],
            ]
        );

        if (Schema::hasColumn('meeting_registrations', 'payment_provider')) {
            $registration->payment_provider = 'stripe';
            $registration->save();
        }

        return [
            'ok' => true,
            'url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    public function confirmStripeCheckout(string $sessionId): array
    {
        $ready = $this->stripeService()->assertReady();
        if (!$ready['ok']) {
            return $ready;
        }

        Stripe::setApiKey(config('services.stripe.secret'));
        $session = StripeCheckoutSession::retrieve($sessionId);

        if (($session->payment_status ?? '') !== 'paid') {
            return ['ok' => false, 'status' => 422, 'message' => 'Payment was not completed.'];
        }

        $registrationId = (int) ($session->metadata['meeting_registration_id'] ?? 0);
        $payment = MeetingPayment::query()
            ->where('stripe_session_id', $session->id)
            ->first();

        if (!$payment && $registrationId > 0) {
            $payment = MeetingPayment::query()
                ->where('meeting_registration_id', $registrationId)
                ->where('provider', 'stripe')
                ->orderByDesc('id')
                ->first();
        }

        if (!$payment) {
            return ['ok' => false, 'status' => 404, 'message' => 'Meeting payment not found.'];
        }

        $registration = MeetingRegistration::find($payment->meeting_registration_id);
        if (!$registration) {
            return ['ok' => false, 'status' => 404, 'message' => 'Meeting registration not found.'];
        }

        if (!in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            $payment->status = 'paid';
            $payment->paid_at = now();
            $meta = is_array($payment->metadata) ? $payment->metadata : [];
            $meta['stripe_session'] = ['id' => $session->id, 'payment_status' => $session->payment_status];
            $payment->metadata = $meta;
            $payment->save();
        }

        if (!$this->registrationIsPaid($registration) || strtolower((string) ($registration->status ?? '')) !== 'approved') {
            $this->activateAfterPayment($registration->fresh(), 'stripe', $session->id);
        }

        return [
            'ok' => true,
            'message' => 'Payment confirmed. Your meeting booking is confirmed.',
            'registration_id' => $registration->id,
            'email' => $registration->email,
            'schedule_label' => $registration->schedule_label,
        ];
    }

    public function requestMomo(MeetingRegistration $registration, string $phone, string $mno = 'mtn'): array
    {
        if (!$this->mopay()->isConfigured()) {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Mobile Money is not configured. Set MOPAY_AUTH_KEY and MOPAY_SERVER_BASE_URL.',
            ];
        }

        if ($this->registrationIsPaid($registration)) {
            return ['ok' => false, 'status' => 422, 'message' => 'This booking is already paid.'];
        }

        $amount = $this->feeRwf();
        if ($amount < 1) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Meeting booking fee could not be converted to RWF. Try again in a moment.',
            ];
        }

        $msisdn = $this->mopay()->normalizeMsisdn($phone);
        if (strlen($msisdn) < 12) {
            return ['ok' => false, 'status' => 422, 'message' => 'Enter a valid MTN/Airtel Rwanda mobile money number.'];
        }

        $receiverPayload = app(PaymentReceiverService::class)->resolve(null);
        $receiver = (string) ($receiverPayload['receiver_account_no'] ?? '');
        if ($receiver === '') {
            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Payment receive number is not set. Ask an admin to set it under Settings → Payments.',
            ];
        }

        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) config('services.mopay.project_slug', 'XANDER')) ?: 'XANDER');
        $transactionId = $this->mopay()->newTransactionId($slug . '_MTG_' . $registration->id);
        $currency = (string) config('services.mopay.default_currency', 'RWF');
        $prefix = (string) config('services.mopay.message_prefix', 'XANDER');
        $title = (string) config('services.mopay.payment_title', 'Xander_meeting_payment');

        try {
            $gatewayResult = $this->mopay()->initiateCollection([
                'account_no' => $msisdn,
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'title' => $title,
                'details' => 'Meeting booking: ' . ($registration->full_name ?: $registration->email),
                'message' => $prefix . '_MEETING_PAYMENT',
                'transfer_message' => $prefix . '_RECEIVER_TRANSFER',
                'receiver_account_no' => $receiver,
                'currency' => $currency,
                'country_code' => (string) config('services.mopay.default_country_code', 'rw'),
                'mno' => $mno ?: (string) config('services.mopay.default_mno', 'mtn'),
                'use_transfer' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('Meeting MoPay request failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'status' => 502, 'message' => 'Unable to reach Mobile Money gateway. Try again shortly.'];
        }

        $body = is_array($gatewayResult['response'] ?? null) ? $gatewayResult['response'] : [];
        $httpOk = (bool) ($gatewayResult['ok'] ?? false);
        $msisdnStored = (string) ($gatewayResult['msisdn'] ?? $msisdn);
        $transactionId = (string) ($gatewayResult['transaction_id'] ?? $transactionId);
        $failureReason = $httpOk
            ? null
            : (string) ($gatewayResult['error_message'] ?? $this->mopay()->humanizeError($body));

        $payment = MeetingPayment::create([
            'meeting_registration_id' => $registration->id,
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
                'receiver_phone' => $receiverPayload['display_momo_phone'] ?? null,
                'failure_reason' => $failureReason,
            ],
        ]);

        if (Schema::hasColumn('meeting_registrations', 'payment_provider')) {
            $registration->payment_provider = 'mopay';
            $registration->save();
        }

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
            'message' => 'Approve the payment prompt on your phone to confirm your booking.',
            'payment_id' => $payment->id,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
            'msisdn' => $msisdnStored,
        ];
    }

    public function syncMomoFromGateway(string $transactionId): array
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
        $payment = MeetingPayment::query()
            ->whereIn('external_reference', array_values(array_unique([$transactionId, $baseRef])))
            ->orderByDesc('id')
            ->first();

        if (!$payment) {
            return ['ok' => false, 'message' => 'Meeting payment not found.'];
        }

        $registration = MeetingRegistration::find($payment->meeting_registration_id);

        if (in_array($payment->status, ['paid', 'succeeded', 'completed'], true)) {
            if ($registration && strtolower((string) ($registration->status ?? '')) !== 'approved') {
                $this->activateAfterPayment($registration, 'mopay', $payment->external_reference);
            }

            return [
                'ok' => true,
                'message' => 'Payment already confirmed.',
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'transaction_id' => $payment->external_reference,
                    'amount' => (int) round($payment->amount_cents / 100),
                ],
                'registration' => $registration ? [
                    'id' => $registration->id,
                    'status' => $registration->status,
                    'email' => $registration->email,
                    'schedule_label' => $registration->schedule_label,
                ] : null,
            ];
        }

        $gateway = $this->mopay()->transactionStatus((string) $payment->external_reference);
        if (!$gateway['success']) {
            $gateway = $this->mopay()->transactionStatus((string) $payment->external_reference . '_T');
        }

        if ($gateway['success']) {
            $this->handleWebhookSuccess(
                (string) $payment->external_reference,
                is_array($gateway['response']) ? $gateway['response'] : ['source' => 'status_poll']
            );
            $payment = $payment->fresh();
            $registration = $registration?->fresh();

            return [
                'ok' => true,
                'message' => 'Payment confirmed. Your meeting booking is confirmed.',
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'transaction_id' => $payment->external_reference,
                    'amount' => (int) round($payment->amount_cents / 100),
                ],
                'registration' => $registration ? [
                    'id' => $registration->id,
                    'status' => $registration->status,
                    'email' => $registration->email,
                    'schedule_label' => $registration->schedule_label,
                ] : null,
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
                    'error_message' => (string) ($meta['failure_reason'] ?? ''),
                ],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Payment still processing. Approve the prompt on your phone if asked.',
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'transaction_id' => $payment->external_reference,
                'amount' => (int) round($payment->amount_cents / 100),
            ],
        ];
    }

    public function handleWebhookSuccess(string $transactionId, array $data): bool
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
        $payment = MeetingPayment::query()
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

        $registration = MeetingRegistration::find($payment->meeting_registration_id);
        if ($registration) {
            $this->activateAfterPayment($registration, 'mopay', $payment->external_reference);
        }

        return true;
    }

    public function handleWebhookFailure(string $transactionId, array $data, ?string $reason = null): bool
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
        $payment = MeetingPayment::query()
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
            : $this->mopay()->humanizeError($data);

        $payment->status = 'failed';
        $meta = is_array($payment->metadata) ? $payment->metadata : [];
        $meta['failure_reason'] = $friendly;
        $meta['webhook'] = $data;
        $payment->metadata = $meta;
        $payment->save();

        return true;
    }

    public function findByExternalReference(string $transactionId): ?MeetingPayment
    {
        $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;

        return MeetingPayment::query()
            ->whereIn('external_reference', array_values(array_unique([$transactionId, $baseRef])))
            ->orderByDesc('id')
            ->first();
    }
}
