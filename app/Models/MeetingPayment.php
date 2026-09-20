<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingPayment extends Model
{
    protected $fillable = [
        'meeting_registration_id',
        'amount_cents',
        'currency',
        'provider',
        'stripe_session_id',
        'external_reference',
        'msisdn',
        'status',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'amount_cents' => 'integer',
        'meeting_registration_id' => 'integer',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(MeetingRegistration::class, 'meeting_registration_id');
    }
}
