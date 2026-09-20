<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetSubscriptionPromoCode;
use App\Models\User;
use App\Support\PlatformInstitutionHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeetSubscriptionPromoAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $codes = MeetSubscriptionPromoCode::query()
            ->with('plan:id,name,slug')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MeetSubscriptionPromoCode $promo) => $this->payload($promo));

        return response()->json(['promo_codes' => $codes]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('meet_subscription_promo_codes', 'code')],
            'label' => 'nullable|string|max:160',
            'max_uses' => 'nullable|integer|min:1|max:100000',
            'plan_id' => 'nullable|integer|exists:meet_subscription_plans,id',
            'expires_at' => 'nullable|date',
        ]);

        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            $code = $this->generateUniqueCode();
        }

        $promo = MeetSubscriptionPromoCode::create([
            'code' => $code,
            'label' => $data['label'] ?? 'Complimentary Meet subscription',
            'max_uses' => $data['max_uses'] ?? 1,
            'uses_count' => 0,
            'is_active' => true,
            'plan_id' => $data['plan_id'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $actor?->email,
        ]);

        return response()->json([
            'message' => 'Promo code generated',
            'promo_code' => $this->payload($promo->load('plan:id,name,slug')),
        ], 201);
    }

    public function update(Request $request, MeetSubscriptionPromoCode $promoCode): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'label' => 'nullable|string|max:160',
            'max_uses' => 'sometimes|integer|min:1|max:100000',
            'expires_at' => 'nullable|date',
        ]);

        $promoCode->update($data);

        return response()->json([
            'message' => 'Promo code updated',
            'promo_code' => $this->payload($promoCode->fresh()->load('plan:id,name,slug')),
        ]);
    }

    private function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = 'XMEET-'.$suffix;
        } while (MeetSubscriptionPromoCode::where('code', $code)->exists());

        return $code;
    }

    private function payload(MeetSubscriptionPromoCode $promo): array
    {
        return [
            'id' => $promo->id,
            'code' => $promo->code,
            'label' => $promo->label,
            'max_uses' => (int) $promo->max_uses,
            'uses_count' => (int) $promo->uses_count,
            'is_active' => (bool) $promo->is_active,
            'expires_at' => $promo->expires_at?->toIso8601String(),
            'plan_id' => $promo->plan_id,
            'plan_name' => $promo->plan?->name,
            'created_by' => $promo->created_by,
            'created_at' => $promo->created_at?->toIso8601String(),
            'redeemable' => $promo->isRedeemable(),
        ];
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $actor = PlatformInstitutionHelper::resolveActorFromRequest($request);
        if (!$actor || !$this->isAdminStaff($actor)) {
            return response()->json([
                'message' => 'Only platform administrators can manage Meet promo codes.',
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
}
