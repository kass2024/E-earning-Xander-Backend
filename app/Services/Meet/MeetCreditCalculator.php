<?php

namespace App\Services\Meet;

use App\Models\MeetSubscriptionPlan;

/**
 * Smart credit estimation for Daily.co API usage with profit margin.
 *
 * Daily.co pricing reference (2025):
 *   - Video participant-minute: ~$0.0035
 *   - Audio participant-minute: ~$0.0015
 *   - Recording storage: ~$0.05/GB/month
 *   - Cloud recording processing: ~$0.013/min
 *
 * We apply a 35% margin on top of raw Daily costs, baked into plan pricing.
 * 1 credit = 1 participant-minute of video (audio-only uses 0.4 credits).
 */
class MeetCreditCalculator
{
    public const DAILY_VIDEO_COST_USD = 0.0035;
    public const DAILY_AUDIO_COST_USD = 0.0015;
    public const DAILY_RECORDING_COST_USD = 0.013;
    public const DAILY_STORAGE_COST_USD_PER_GB = 0.05;
    public const PROFIT_MARGIN = 0.35;

    /** Credits per participant-minute (video). */
    public const CREDITS_PER_VIDEO_MINUTE = 1;

    /** Credits per participant-minute (audio-only). */
    public const CREDITS_PER_AUDIO_MINUTE = 1;

    /** Credits per minute of cloud recording processing. */
    public const CREDITS_PER_RECORDING_MINUTE = 3;

    /** Credits per MB of recording storage per month. */
    public const CREDITS_PER_STORAGE_MB = 1;

    public function participantMinutesToCredits(int $participants, int $minutes, bool $video = true): int
    {
        $rate = $video ? self::CREDITS_PER_VIDEO_MINUTE : self::CREDITS_PER_AUDIO_MINUTE;

        return max(1, $participants * $minutes * $rate);
    }

    public function recordingMinutesToCredits(int $minutes): int
    {
        return max(1, $minutes * self::CREDITS_PER_RECORDING_MINUTE);
    }

    public function storageMbToCredits(int $mb): int
    {
        return max(1, (int) ceil($mb * self::CREDITS_PER_STORAGE_MB / 100));
    }

    /**
     * Estimate monthly credits needed for a plan tier.
     * Assumes avg 60 min meetings, 50% utilization of max participants.
     */
    public function estimateMonthlyCredits(int $maxParticipants, int $estimatedMeetingsPerMonth = 20): int
    {
        $avgParticipants = max(1, (int) ceil($maxParticipants * 0.5));
        $avgDuration = 60;

        return $this->participantMinutesToCredits($avgParticipants, $avgDuration) * $estimatedMeetingsPerMonth;
    }

    /**
     * Raw Daily cost in USD for given usage (before margin).
     */
    public function rawDailyCostUsd(int $participantMinutes, int $recordingMinutes = 0, float $storageGb = 0): float
    {
        $videoCost = $participantMinutes * self::DAILY_VIDEO_COST_USD;
        $recordingCost = $recordingMinutes * self::DAILY_RECORDING_COST_USD;
        $storageCost = $storageGb * self::DAILY_STORAGE_COST_USD_PER_GB;

        return $videoCost + $recordingCost + $storageCost;
    }

    /**
     * Suggested plan price in USD cents with margin applied.
     */
    public function suggestedPriceUsdCents(int $maxParticipants, int $storageMb, int $monthlyCredits): int
    {
        $storageGb = $storageMb / 1024;
        $rawCost = $this->rawDailyCostUsd($monthlyCredits, 0, $storageGb);
        $withMargin = $rawCost * (1 + self::PROFIT_MARGIN);
        $hostingOverhead = 5.0;
        $total = $withMargin + $hostingOverhead;

        return (int) max(2900, round($total * 100));
    }

    /** @return array<string, mixed> */
    public function planCostBreakdown(MeetSubscriptionPlan $plan): array
    {
        $storageGb = $plan->storage_mb / 1024;
        $rawCost = $this->rawDailyCostUsd((int) $plan->monthly_credits, 0, $storageGb);
        $withMargin = $rawCost * (1 + self::PROFIT_MARGIN);

        return [
            'daily_raw_cost_usd' => round($rawCost, 2),
            'with_margin_usd' => round($withMargin, 2),
            'margin_percent' => self::PROFIT_MARGIN * 100,
            'credits_per_video_minute' => self::CREDITS_PER_VIDEO_MINUTE,
            'estimated_meeting_hours' => $plan->estimatedMeetingHours(),
        ];
    }
}
