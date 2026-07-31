<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetUsageCredit extends Model
{
    protected $fillable = [
        'subscription_id', 'credits_allocated', 'credits_used',
        'storage_mb_allocated', 'storage_mb_used', 'period_start',
        'period_end', 'is_exhausted',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'is_exhausted' => 'boolean',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MeetSubscription::class, 'subscription_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MeetUsageLog::class, 'usage_credit_id');
    }

    public function creditsRemaining(): int
    {
        return max(0, (int) $this->credits_allocated - (int) $this->credits_used);
    }

    public function storageRemainingMb(): int
    {
        return max(0, (int) $this->storage_mb_allocated - (int) $this->storage_mb_used);
    }

    public function usagePercent(): float
    {
        if ($this->credits_allocated <= 0) {
            return 100.0;
        }

        return round(($this->credits_used / $this->credits_allocated) * 100, 1);
    }
}
