<?php

namespace App\Support;

use App\Services\Meet\MeetUsageService;
use Illuminate\Http\JsonResponse;

trait MeetSubscriptionGate
{
    protected function gateMeetingAccess(?int $institutionId, ?int $userId, int $participants = 1, ?string $role = null): ?JsonResponse
    {
        $roleNormalized = strtolower(trim((string) $role));

        // Xander Learning Hub — meetings/webinars/live classes are included for staff roles.
        if (in_array($roleNormalized, ['admin', 'staff', 'instructor', 'partner_company'], true)) {
            return null;
        }

        // Billing gate applies only on the dedicated Xander Meet product (meet.xandertech.llc).
        $frontendUrl = strtolower(rtrim((string) config('app.frontend_url', ''), '/'));
        $meetProduct = str_contains($frontendUrl, 'meet.xandertech.llc');
        if (!$meetProduct) {
            return null;
        }

        /** @var MeetUsageService $usage */
        $usage = app(MeetUsageService::class);
        $sub = $usage->resolveSubscription($institutionId, $userId);

        if (!$sub) {
            return response()->json([
                'message' => 'No active Xander Meet subscription. Subscribe at meet.xandertech.llc/pricing',
                'reason' => 'no_subscription',
            ], 403);
        }

        $check = $usage->canHostMeeting($sub, $participants);
        if (!($check['ok'] ?? false)) {
            return response()->json([
                'message' => $check['message'] ?? 'Meeting access denied.',
                'reason' => $check['reason'] ?? 'access_denied',
            ], 403);
        }

        return null;
    }
}
