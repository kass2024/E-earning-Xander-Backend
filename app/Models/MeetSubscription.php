<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MeetSubscription extends Model
{
    protected $fillable = [
        'platform_institution_id', 'user_id', 'plan_id', 'status',
        'billing_provider', 'stripe_subscription_id', 'stripe_customer_id',
        'current_period_start', 'current_period_end', 'cancelled_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MeetSubscriptionPlan::class, 'plan_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(PlatformInstitution::class, 'platform_institution_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MeetSubscriptionPayment::class, 'subscription_id');
    }

    public function usageCredits(): HasMany
    {
        return $this->hasMany(MeetUsageCredit::class, 'subscription_id');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(MeetUsageLog::class, 'subscription_id');
    }

    public function currentUsage(): HasOne
    {
        return $this->hasOne(MeetUsageCredit::class, 'subscription_id')
            ->where('is_exhausted', false)
            ->where('period_end', '>', now())
            ->latest('period_start');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->current_period_end
            && $this->current_period_end->isFuture();
    }

    public function hasCredits(): bool
    {
        $usage = $this->currentUsage;
        if (!$usage) {
            return false;
        }

        return !$usage->is_exhausted
            && $usage->credits_used < $usage->credits_allocated;
    }
}
