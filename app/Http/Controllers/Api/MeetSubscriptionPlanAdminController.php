<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetSubscriptionPlan;
use App\Models\User;
use App\Services\Meet\MeetCreditCalculator;
use App\Support\PlatformInstitutionHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeetSubscriptionPlanAdminController extends Controller
{
    public function __construct(
        private readonly MeetCreditCalculator $calculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $plans = MeetSubscriptionPlan::query()
            ->withCount('subscriptions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MeetSubscriptionPlan $plan) => $this->planPayload($plan));

        return response()->json(['plans' => $plans]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $data = $this->validatePlanData($request);
        $plan = MeetSubscriptionPlan::create($data);

        return response()->json([
            'message' => 'Subscription plan created',
            'plan' => $this->planPayload($plan->fresh()->loadCount('subscriptions')),
        ], 201);
    }

    public function update(Request $request, MeetSubscriptionPlan $plan): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $data = $this->validatePlanData($request, $plan);
        $plan->update($data);

        return response()->json([
            'message' => 'Subscription plan updated',
            'plan' => $this->planPayload($plan->fresh()->loadCount('subscriptions')),
        ]);
    }

    public function destroy(Request $request, MeetSubscriptionPlan $plan): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $subscriptionCount = $plan->subscriptions()->count();

        if ($subscriptionCount > 0) {
            $plan->update(['is_active' => false]);

            return response()->json([
                'message' => 'Plan deactivated because existing subscriptions reference it.',
                'soft_deleted' => true,
                'plan' => $this->planPayload($plan->fresh()->loadCount('subscriptions')),
            ]);
        }

        $plan->delete();

        return response()->json([
            'message' => 'Subscription plan deleted',
            'soft_deleted' => false,
        ]);
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$actor || !$this->isAdminStaff($actor)) {
            return response()->json([
                'message' => 'Only platform administrators can manage Meet subscription plans.',
            ], 403);
        }

        return null;
    }

    private function isAdminStaff(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        return in_array($role, ['admin', 'staff'], true);
    }

    private function validatePlanData(Request $request, ?MeetSubscriptionPlan $plan = null): array
    {
        $rules = [
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('meet_subscription_plans', 'slug')->ignore($plan?->id),
            ],
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:5000',
            'max_participants' => 'required|integer|min:1|max:10000',
            'storage_mb' => 'required|integer|min:0',
            'monthly_credits' => 'required|integer|min:0',
            'price_usd_cents' => 'nullable|integer|min:0',
            'price_usd' => 'nullable|numeric|min:0',
            'price_rwf' => 'required|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
            'features' => 'nullable|array',
            'features.*' => 'string|max:500',
        ];

        $data = $request->validate($rules);

        if (!array_key_exists('price_usd_cents', $data) || $data['price_usd_cents'] === null) {
            if (array_key_exists('price_usd', $data) && $data['price_usd'] !== null) {
                $data['price_usd_cents'] = (int) round(((float) $data['price_usd']) * 100);
            } elseif ($plan) {
                $data['price_usd_cents'] = $plan->price_usd_cents;
            } else {
                $data['price_usd_cents'] = 0;
            }
        }

        unset($data['price_usd']);

        if (!array_key_exists('is_active', $data)) {
            $data['is_active'] = $plan?->is_active ?? true;
        }

        if (!array_key_exists('sort_order', $data)) {
            $data['sort_order'] = $plan?->sort_order ?? 0;
        }

        $data['features'] = array_values(array_filter(
            array_map('trim', $data['features'] ?? []),
            fn (string $line) => $line !== '',
        ));

        return $data;
    }

    private function planPayload(MeetSubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'max_participants' => $plan->max_participants,
            'storage_mb' => $plan->storage_mb,
            'storage_gb' => $plan->storageGb(),
            'monthly_credits' => $plan->monthly_credits,
            'estimated_meeting_hours' => $plan->estimatedMeetingHours(),
            'price_usd_cents' => $plan->price_usd_cents,
            'price_usd' => round($plan->price_usd_cents / 100, 2),
            'price_rwf' => $plan->price_rwf,
            'is_active' => (bool) $plan->is_active,
            'sort_order' => $plan->sort_order,
            'features' => $plan->features ?? [],
            'subscription_count' => (int) ($plan->subscriptions_count ?? $plan->subscriptions()->count()),
            'cost_breakdown' => $this->calculator->planCostBreakdown($plan),
        ];
    }
}
