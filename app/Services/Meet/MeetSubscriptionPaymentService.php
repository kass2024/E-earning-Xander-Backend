<?php

namespace App\Services\Meet;

use App\Models\MeetSubscription;
use App\Models\MeetSubscriptionPayment;
use App\Models\MeetSubscriptionPlan;
use App\Models\PlatformInstitution;
use App\Models\User;
use App\Services\Mopay\MopayGatewayClient;
use App\Services\MopayPaymentService;
use App\Services\PaymentReceiverService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

class MeetSubscriptionPaymentService
{
    public function __construct(
        private readonly MeetUsageService $usageService,
        private readonly MopayGatewayClient $mopay,
        private readonly MopayPaymentService $mopayPayments,
    ) {}

    public function createSubscription(
        MeetSubscriptionPlan $plan,
        ?int $institutionId = null,
        ?int $userId = null,
    ): MeetSubscription {
        return MeetSubscription::create([
            'platform_institution_id' => $institutionId,
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'status' => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    public function createStripeCheckout(MeetSubscription $subscription, string $successUrl, string $cancelUrl): array
    {
        $plan = $subscription->plan;
        $secret = config('services.stripe.secret');
        if (!$secret) {
            return ['ok' => false, 'message' => 'Stripe is not configured.'];
        }

        Stripe::setApiKey($secret);

        $payment = MeetSubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'amount_cents' => $plan->price_usd_cents,
            'currency' => 'USD',
            'provider' => 'stripe',
            'status' => 'pending',
            'external_reference' => 'XM-SUB-' . Str::upper(Str::random(12)),
        ]);

        $session = StripeSession::create([
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "Xander Meet — {$plan->name}",
                        'description' => "Up to {$plan->max_participants} participants, {$plan->storageGb()}GB storage, {$plan->monthly_credits} credits/month",
                    ],
                    'unit_amount' => $plan->price_usd_cents,
                    'recurring' => ['interval' => 'month'],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}&ref=' . $payment->external_reference,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'subscription_id' => (string) $subscription->id,
                'payment_id' => (string) $payment->id,
                'plan_id' => (string) $plan->id,
                'product' => 'xander_meet',
            ],
        ]);

        $payment->update(['stripe_session_id' => $session->id]);

        return [
            'ok' => true,
            'checkout_url' => $session->url,
            'session_id' => $session->id,
            'reference' => $payment->external_reference,
        ];
    }

    /** @return array<string, mixed> */
    public function confirmStripeCheckout(string $sessionId): array
    {
        $secret = config('services.stripe.secret');
        if (!$secret) {
            return ['ok' => false, 'message' => 'Stripe not configured.'];
        }

        Stripe::setApiKey($secret);
        $session = StripeSession::retrieve($sessionId);

        if ($session->payment_status !== 'paid') {
            return ['ok' => false, 'message' => 'Payment not completed.'];
        }

        $subscriptionId = (int) ($session->metadata['subscription_id'] ?? 0);
        $paymentId = (int) ($session->metadata['payment_id'] ?? 0);

        $subscription = MeetSubscription::find($subscriptionId);
        $payment = MeetSubscriptionPayment::find($paymentId);

        if (!$subscription || !$payment) {
            return ['ok' => false, 'message' => 'Subscription record not found.'];
        }

        if ($payment->isPaid()) {
            return ['ok' => true, 'message' => 'Already activated.', 'subscription' => $this->usageService->usageSummary($subscription)];
        }

        $this->activateSubscription($subscription, $payment, 'stripe', $session->subscription ?? null);

        return ['ok' => true, 'subscription' => $this->usageService->usageSummary($subscription)];
    }

    /** @return array<string, mixed> */
    public function requestMomoPayment(MeetSubscription $subscription, string $phone, string $mno = 'mtn'): array
    {
        if (!$this->mopay->isConfigured()) {
            return ['ok' => false, 'message' => 'Mobile Money is not configured.'];
        }

        $plan = $subscription->plan;
        $msisdn = $this->mopay->normalizeMsisdn($phone);
        $amount = (int) $plan->price_rwf;
        $transactionId = 'XM' . strtoupper(Str::random(14));

        $receiver = app(PaymentReceiverService::class)->receiverAccountNo(null);

        $payment = MeetSubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'amount_cents' => $amount * 100,
            'currency' => 'RWF',
            'provider' => 'mopay',
            'status' => 'processing',
            'external_reference' => $transactionId,
            'msisdn' => $msisdn,
        ]);

        $result = $this->mopay->initiateCollection([
            'account_no' => $msisdn,
            'amount' => $amount,
            'transaction_id' => $transactionId,
            'receiver_account_no' => $receiver,
            'use_transfer' => true,
            'title' => config('services.mopay.payment_title', 'Xander_meet_subscription'),
            'details' => "Xander Meet {$plan->name} monthly subscription",
        ]);

        $httpOk = ($result['http_status'] ?? 0) >= 200 && ($result['http_status'] ?? 0) < 300;
        if (!$httpOk) {
            $payment->update(['status' => 'failed', 'metadata' => $result]);
            return ['ok' => false, 'message' => $result['message'] ?? 'MoPay request failed.', 'reference' => $transactionId];
        }

        return [
            'ok' => true,
            'reference' => $transactionId,
            'message' => 'Check your phone to approve the payment.',
            'amount_rwf' => $amount,
        ];
    }

    /** @return array<string, mixed> */
    public function syncMomoStatus(string $reference): array
    {
        $payment = MeetSubscriptionPayment::where('external_reference', $reference)->first();
        if (!$payment) {
            return ['ok' => false, 'message' => 'Payment not found.'];
        }

        if ($payment->isPaid()) {
            $subscription = $payment->subscription;
            return ['ok' => true, 'status' => 'paid', 'subscription' => $this->usageService->usageSummary($subscription)];
        }

        $status = $this->mopay->transactionStatus($reference);
        if ($this->mopay->isSettledSuccess($status)) {
            $this->activateSubscription($payment->subscription, $payment, 'mopay');
            return ['ok' => true, 'status' => 'paid', 'subscription' => $this->usageService->usageSummary($payment->subscription)];
        }

        if ($this->mopay->isSettledFailure($status)) {
            $payment->update(['status' => 'failed']);
            return ['ok' => false, 'status' => 'failed', 'message' => 'Payment was declined or timed out.'];
        }

        return ['ok' => true, 'status' => 'processing', 'message' => 'Waiting for payment confirmation.'];
    }

    public function handleMopayWebhook(string $jwtBody): array
    {
        try {
            $payload = $this->mopayPayments->verifyWebhookJwt($jwtBody);
        } catch (\Throwable $e) {
            Log::warning('Meet MoPay webhook JWT failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 401];
        }

        $data = $payload['data'] ?? null;
        if (is_string($data) && str_starts_with(ltrim($data), '{')) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $transactionId = is_array($data)
            ? (string) ($data['transactionId'] ?? $data['referenceId'] ?? $data['transaction_id'] ?? '')
            : '';
        if ($transactionId === '') {
            $transactionId = (string) ($payload['transactionId'] ?? $payload['transaction_id'] ?? '');
        }

        if ($transactionId === '') {
            return ['ok' => false, 'status' => 400, 'message' => 'Missing transaction id'];
        }

        $payment = MeetSubscriptionPayment::where('external_reference', $transactionId)->first();
        if (!$payment) {
            $baseRef = preg_replace('/_T$/', '', $transactionId) ?: $transactionId;
            $payment = MeetSubscriptionPayment::where('external_reference', $baseRef)->first();
        }

        if (!$payment) {
            return ['ok' => false, 'status' => 404, 'message' => 'Transaction not found'];
        }

        if ($this->mopay->isSettledSuccess($payload)) {
            if (!$payment->isPaid()) {
                $this->activateSubscription($payment->subscription, $payment, 'mopay');
            }
            return ['ok' => true, 'status' => 200];
        }

        if ($this->mopay->isSettledFailure($payload)) {
            $payment->update(['status' => 'failed']);
        }

        return ['ok' => true, 'status' => 200];
    }

    private function activateSubscription(
        MeetSubscription $subscription,
        MeetSubscriptionPayment $payment,
        string $provider,
        ?string $stripeSubscriptionId = null,
    ): void {
        $now = now();
        $periodEnd = $now->copy()->addMonth();

        $payment->update(['status' => 'paid', 'paid_at' => $now]);

        $subscription->update([
            'status' => 'active',
            'billing_provider' => $provider,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
        ]);

        $this->usageService->allocatePeriodCredits($subscription->fresh(['plan']));
    }
}
