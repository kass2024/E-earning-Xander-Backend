<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetSubscriptionPayment extends Model
{
    protected $fillable = [
        'subscription_id', 'plan_id', 'amount_cents', 'currency', 'provider',
        'status', 'external_reference', 'stripe_session_id', 'msisdn',
        'metadata', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MeetSubscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MeetSubscriptionPlan::class, 'plan_id');
    }

    public function isPaid(): bool
    {
        return in_array($this->status, ['paid', 'succeeded', 'completed'], true);
    }
}
