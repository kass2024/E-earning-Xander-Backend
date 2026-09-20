<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingRegistration extends Model
{
    protected $fillable = [
        'user_id',
        'available_schedule_id',
        'platform_institution_id',
        'schedule_label',
        'full_name',
        'email',
        'phone',
        'country',
        'notes',
        'status',
        'payment_status',
        'payment_provider',
        'payment_reference',
        'paid_at',
        'cancel_token',
        'rejected_reason',
        'zoom_meeting_id',
        'zoom_join_url',
        'zoom_start_time',
        'reminder_sent_at',
        'final_reminder_sent_at',
    ];

    protected $casts = [
        'zoom_start_time' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'final_reminder_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'platform_institution_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availableSchedule(): BelongsTo
    {
        return $this->belongsTo(AvailableSchedule::class);
    }
}
