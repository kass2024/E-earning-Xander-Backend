<?php

namespace App\Support;

use App\Services\Meet\MeetUsageService;
use Illuminate\Http\JsonResponse;

trait MeetSubscriptionGate
{
    protected function gateMeetingAccess(?int $institutionId, ?int $userId, int $participants = 1): ?JsonResponse
    {
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
