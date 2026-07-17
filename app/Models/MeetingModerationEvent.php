<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingModerationEvent extends Model
{
    protected $fillable = [
        'meeting_key',
        'actor_user_id',
        'target_user_id',
        'target_session_id',
        'action',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
