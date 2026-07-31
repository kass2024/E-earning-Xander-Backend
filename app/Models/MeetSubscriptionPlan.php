<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetSubscriptionPlan extends Model
{
    protected $fillable = [
        'slug', 'name', 'description', 'max_participants', 'storage_mb',
        'monthly_credits', 'price_usd_cents', 'price_rwf', 'is_active',
        'sort_order', 'features',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'features' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MeetSubscription::class, 'plan_id');
    }

    public function storageGb(): float
    {
        return round($this->storage_mb / 1024, 1);
    }

    public function estimatedMeetingHours(): int
    {
        return (int) max(1, round($this->monthly_credits / max(1, $this->max_participants) / 60));
    }
}
