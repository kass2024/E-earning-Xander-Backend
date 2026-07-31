<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetUsageLog extends Model
{
    protected $fillable = [
        'subscription_id', 'usage_credit_id', 'user_id', 'event_type',
        'credits_consumed', 'storage_mb_delta', 'participant_count',
        'duration_minutes', 'meeting_ref', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MeetSubscription::class, 'subscription_id');
    }

    public function usageCredit(): BelongsTo
    {
        return $this->belongsTo(MeetUsageCredit::class, 'usage_credit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
