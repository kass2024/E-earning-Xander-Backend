<?php

namespace App\Services\Meet;

use App\Models\MeetSubscription;
use App\Models\MeetSubscriptionPlan;
use App\Models\MeetUsageCredit;
use App\Models\MeetUsageLog;
use App\Models\PlatformInstitution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeetUsageService
{
    public function __construct(
        private readonly MeetCreditCalculator $calculator,
    ) {}

    public function resolveSubscription(?int $institutionId = null, ?int $userId = null): ?MeetSubscription
    {
        if ($institutionId) {
            $sub = MeetSubscription::query()
                ->where('platform_institution_id', $institutionId)
                ->where('status', 'active')
                ->where('current_period_end', '>', now())
                ->with(['plan', 'currentUsage'])
                ->latest('id')
                ->first();
            if ($sub) {
                return $sub;
            }
        }

        if ($userId) {
            return MeetSubscription::query()
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->where('current_period_end', '>', now())
                ->with(['plan', 'currentUsage'])
                ->latest('id')
                ->first();
        }

        return null;
    }

    public function canHostMeeting(MeetSubscription $subscription, int $participantCount = 1): array
    {
        if (!$subscription->isActive()) {
            return ['ok' => false, 'reason' => 'subscription_inactive', 'message' => 'Your subscription is not active. Please renew to host meetings.'];
        }

        $usage = $subscription->currentUsage;
        if (!$usage) {
            return ['ok' => false, 'reason' => 'no_credits', 'message' => 'No usage credits allocated for this billing period.'];
        }

        if ($usage->is_exhausted || $usage->creditsRemaining() <= 0) {
            return ['ok' => false, 'reason' => 'credits_exhausted', 'message' => 'Your meeting credits are exhausted. Please upgrade your plan or wait for the next billing cycle.'];
        }

        $plan = $subscription->plan;
        if ($participantCount > $plan->max_participants) {
            return [
                'ok' => false,
                'reason' => 'participant_limit',
                'message' => "Your plan allows up to {$plan->max_participants} participants. You requested {$participantCount}.",
            ];
        }

        return ['ok' => true, 'usage' => $usage, 'plan' => $plan];
    }

    public function consumeCredits(
        MeetSubscription $subscription,
        int $participants,
        int $minutes,
        string $eventType = 'meeting_session',
        ?string $meetingRef = null,
        ?int $userId = null,
        bool $video = true,
    ): MeetUsageLog {
        $credits = $this->calculator->participantMinutesToCredits($participants, $minutes, $video);

        return $this->deductCredits($subscription, $credits, $eventType, $meetingRef, $userId, [
            'participants' => $participants,
            'minutes' => $minutes,
            'video' => $video,
        ], $participants, $minutes);
    }

    public function consumeRecordingStorage(MeetSubscription $subscription, int $storageMb, ?string $meetingRef = null): MeetUsageLog
    {
        $credits = $this->calculator->storageMbToCredits($storageMb);

        return $this->deductCredits($subscription, $credits, 'recording_storage', $meetingRef, null, [
            'storage_mb' => $storageMb,
        ], 0, 0, $storageMb);
    }

    /** @param array<string, mixed> $meta */
    private function deductCredits(
        MeetSubscription $subscription,
        int $credits,
        string $eventType,
        ?string $meetingRef,
        ?int $userId,
        array $meta,
        int $participants = 0,
        int $minutes = 0,
        int $storageMb = 0,
    ): MeetUsageLog {
        return DB::transaction(function () use ($subscription, $credits, $eventType, $meetingRef, $userId, $meta, $participants, $minutes, $storageMb) {
            $usage = MeetUsageCredit::query()
                ->where('subscription_id', $subscription->id)
                ->where('is_exhausted', false)
                ->where('period_end', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$usage) {
                throw new \RuntimeException('No active usage credit period.');
            }

            $usage->credits_used = min($usage->credits_allocated, $usage->credits_used + $credits);
            if ($storageMb > 0) {
                $usage->storage_mb_used = min($usage->storage_mb_allocated, $usage->storage_mb_used + $storageMb);
            }

            if ($usage->credits_used >= $usage->credits_allocated) {
                $usage->is_exhausted = true;
                Log::warning('Meet subscription credits exhausted', [
                    'subscription_id' => $subscription->id,
                    'institution_id' => $subscription->platform_institution_id,
                ]);
            }

            $usage->save();

            return MeetUsageLog::create([
                'subscription_id' => $subscription->id,
                'usage_credit_id' => $usage->id,
                'user_id' => $userId,
                'event_type' => $eventType,
                'credits_consumed' => $credits,
                'storage_mb_delta' => $storageMb,
                'participant_count' => $participants,
                'duration_minutes' => $minutes,
                'meeting_ref' => $meetingRef,
                'metadata' => $meta,
            ]);
        });
    }

    public function allocatePeriodCredits(MeetSubscription $subscription): MeetUsageCredit
    {
        $plan = $subscription->plan;
        $start = now();
        $end = $subscription->current_period_end ?? now()->addMonth();

        return MeetUsageCredit::create([
            'subscription_id' => $subscription->id,
            'credits_allocated' => $plan->monthly_credits,
            'credits_used' => 0,
            'storage_mb_allocated' => $plan->storage_mb,
            'storage_mb_used' => 0,
            'period_start' => $start,
            'period_end' => $end,
            'is_exhausted' => false,
        ]);
    }

    /** @return array<string, mixed> */
    public function usageSummary(MeetSubscription $subscription): array
    {
        $usage = $subscription->currentUsage;
        $plan = $subscription->plan;

        return [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
            'plan' => [
                'name' => $plan->name,
                'max_participants' => $plan->max_participants,
                'storage_gb' => $plan->storageGb(),
                'monthly_credits' => $plan->monthly_credits,
            ],
            'period' => [
                'start' => $subscription->current_period_start?->toIso8601String(),
                'end' => $subscription->current_period_end?->toIso8601String(),
            ],
            'credits' => $usage ? [
                'allocated' => $usage->credits_allocated,
                'used' => $usage->credits_used,
                'remaining' => $usage->creditsRemaining(),
                'percent_used' => $usage->usagePercent(),
                'is_exhausted' => $usage->is_exhausted,
            ] : null,
            'storage' => $usage ? [
                'allocated_mb' => $usage->storage_mb_allocated,
                'used_mb' => $usage->storage_mb_used,
                'remaining_mb' => $usage->storageRemainingMb(),
            ] : null,
            'can_host' => $this->canHostMeeting($subscription)['ok'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function adminConsumptionReport(?int $institutionId = null): array
    {
        $query = MeetSubscription::query()
            ->with(['plan', 'currentUsage', 'institution', 'user'])
            ->where('status', 'active');

        if ($institutionId) {
            $query->where('platform_institution_id', $institutionId);
        }

        return $query->get()->map(function (MeetSubscription $sub) {
            $usage = $sub->currentUsage;

            return [
                'subscription_id' => $sub->id,
                'tenant' => $sub->institution?->name ?? $sub->user?->name ?? 'Unknown',
                'tenant_type' => $sub->platform_institution_id ? 'institution' : 'individual',
                'plan' => $sub->plan?->name,
                'status' => $sub->status,
                'credits_used' => $usage?->credits_used ?? 0,
                'credits_allocated' => $usage?->credits_allocated ?? 0,
                'credits_remaining' => $usage?->creditsRemaining() ?? 0,
                'storage_used_mb' => $usage?->storage_mb_used ?? 0,
                'is_exhausted' => $usage?->is_exhausted ?? false,
                'period_end' => $sub->current_period_end?->toIso8601String(),
            ];
        })->all();
    }
}
