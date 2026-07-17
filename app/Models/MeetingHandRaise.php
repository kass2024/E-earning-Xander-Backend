<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingHandRaise extends Model
{
    protected $fillable = [
        'meeting_key',
        'meeting_mode',
        'user_id',
        'daily_session_id',
        'participant_name',
        'status',
        'requested_at',
        'reviewed_at',
        'reviewed_by',
        'speaking_duration_seconds',
        'meta',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'speaking_duration_seconds' => 'integer',
        'meta' => 'array',
    ];
}
