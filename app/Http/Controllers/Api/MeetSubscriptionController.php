<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetSubscription;
use App\Models\MeetSubscriptionPlan;
use App\Services\Meet\MeetCreditCalculator;
use App\Services\Meet\MeetSubscriptionPaymentService;
use App\Services\Meet\MeetUsageService;
use App\Services\Mopay\MopayGatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetSubscriptionController extends Controller
{
    public function __construct(
        private readonly MeetSubscriptionPaymentService $payments,
        private readonly MeetUsageService $usage,
        private readonly MeetCreditCalculator $calculator,
    ) {}

    public function plans(): JsonResponse
    {
        $plans = MeetSubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (MeetSubscriptionPlan $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'description' => $p->description,
                'max_participants' => $p->max_participants,
                'storage_gb' => $p->storageGb(),
                'monthly_credits' => $p->monthly_credits,
                'estimated_meeting_hours' => $p->estimatedMeetingHours(),
                'price_usd' => round($p->price_usd_cents / 100, 2),
                'price_rwf' => $p->price_rwf,
                'features' => $p->features ?? [],
                'cost_breakdown' => $this->calculator->planCostBreakdown($p),
            ]);

        return response()->json(['plans' => $plans]);
    }

    public function paymentConfig(MopayGatewayClient $mopay): JsonResponse
    {
        return response()->json([
            'stripe' => [
                'enabled' => filled(config('services.stripe.secret')),
                'publishable_key' => config('services.stripe.key'),
            ],
            'mopay' => [
                'enabled' => $mopay->isConfigured(),
                'currency' => config('services.mopay.default_currency', 'RWF'),
            ],
            'promo' => [
                'enabled' => true,
            ],
        ]);
    }

    public function mySubscription(Request $request): JsonResponse
    {
        $institutionId = $request->integer('institution_id') ?: null;
        $userId = $request->integer('user_id') ?: null;

        $sub = $this->usage->resolveSubscription($institutionId, $userId);
        if (!$sub) {
            return response()->json(['subscription' => null, 'can_host' => false]);
        }

        return response()->json([
            'subscription' => $this->usage->usageSummary($sub),
            'can_host' => $this->usage->canHostMeeting($sub)['ok'],
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => 'required|exists:meet_subscription_plans,id',
            'institution_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'email' => 'nullable|email|max:255',
            'name' => 'nullable|string|max:255',
            'provider' => 'required|in:stripe,mopay,promo',
            'promo_code' => 'required_if:provider,promo|nullable|string|max:64',
        ]);

        $userId = $data['user_id'] ?? null;
        $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
        $name = isset($data['name']) ? trim($data['name']) : null;

        if (!$userId && !$email) {
            return response()->json([
                'ok' => false,
                'message' => 'Enter your email to subscribe. An account will be created after payment.',
            ], 422);
        }

        $plan = MeetSubscriptionPlan::findOrFail($data['plan_id']);

        if ($data['provider'] === 'promo') {
            $result = $this->payments->redeemPromo(
                $plan,
                (string) $data['promo_code'],
                $data['institution_id'] ?? null,
                $userId,
                $email,
                $name,
            );

            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        $subscription = $this->payments->createSubscription(
            $plan,
            $data['institution_id'] ?? null,
            $userId,
            $email,
            $name,
        );

        if ($data['provider'] === 'stripe') {
            $frontend = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', '')), '/');
            $result = $this->payments->createStripeCheckout(
                $subscription,
                "{$frontend}/subscription/success",
                "{$frontend}/subscription/cancel",
            );
            return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
        }

        return response()->json([
            'ok' => true,
            'subscription_id' => $subscription->id,
            'message' => 'Use /payments/momo/request with subscription_id to pay via Mobile Money.',
        ]);
    }

    public function confirmStripe(Request $request): JsonResponse
    {
        $data = $request->validate(['session_id' => 'required|string']);
        $result = $this->payments->confirmStripeCheckout($data['session_id']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function requestMomo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:meet_subscriptions,id',
            'phone' => 'required|string|min:9',
            'email' => 'nullable|email|max:255',
            'name' => 'nullable|string|max:255',
            'mno' => 'nullable|in:mtn,airtel',
        ]);

        $subscription = MeetSubscription::findOrFail($data['subscription_id']);

        if (!$subscription->user_id) {
            $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
            if ($email) {
                $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
                $subscription->update([
                    'metadata' => array_merge($meta, [
                        'email' => $email,
                        'name' => trim((string) ($data['name'] ?? $meta['name'] ?? '')),
                    ]),
                ]);
            }
        }

        $result = $this->payments->requestMomoPayment(
            $subscription->fresh(),
            $data['phone'],
            $data['mno'] ?? 'mtn',
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function momoStatus(string $reference): JsonResponse
    {
        $result = $this->payments->syncMomoStatus($reference);
        return response()->json($result, ($result['ok'] ?? false) ? 200 : 404);
    }

    public function mopayWebhook(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) {
            return response()->json(['status' => 200, 'message' => 'Xander Meet MoPay webhook ready'], 200);
        }

        $body = $request->getContent();
        if (trim($body) === '') {
            return response()->json(['status' => 400, 'message' => 'Empty webhook body'], 400);
        }

        $result = $this->payments->handleMopayWebhook($body);
        $httpStatus = is_int($result['status'] ?? null) ? $result['status'] : 200;

        return response()->json([
            'status' => $httpStatus,
            'message' => $result['message'] ?? 'received',
        ], $httpStatus >= 400 ? $httpStatus : 200);
    }

    public function adminConsumption(Request $request): JsonResponse
    {
        $institutionId = $request->integer('institution_id') ?: null;

        return response()->json([
            'tenants' => $this->usage->adminConsumptionReport($institutionId),
        ]);
    }

    public function checkAccess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'institution_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'participants' => 'nullable|integer|min:1',
        ]);

        $sub = $this->usage->resolveSubscription(
            $data['institution_id'] ?? null,
            $data['user_id'] ?? null,
        );

        if (!$sub) {
            return response()->json([
                'ok' => false,
                'reason' => 'no_subscription',
                'message' => 'No active subscription. Subscribe to host meetings.',
            ], 403);
        }

        $check = $this->usage->canHostMeeting($sub, $data['participants'] ?? 1);

        return response()->json($check, ($check['ok'] ?? false) ? 200 : 403);
    }
}
