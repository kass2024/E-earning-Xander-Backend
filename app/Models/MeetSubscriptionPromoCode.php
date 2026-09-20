<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetSubscriptionPromoCode extends Model
{
    protected $fillable = [
        'code',
        'label',
        'max_uses',
        'uses_count',
        'is_active',
        'expires_at',
        'plan_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MeetSubscriptionPlan::class, 'plan_id');
    }

    public function isRedeemable(?int $planId = null): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->uses_count >= $this->max_uses) {
            return false;
        }

        if ($this->plan_id && $planId && (int) $this->plan_id !== (int) $planId) {
            return false;
        }

        return true;
    }
}
