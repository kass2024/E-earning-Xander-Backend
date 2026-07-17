<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingSpeakingGrant extends Model
{
    protected $fillable = [
        'meeting_key',
        'user_id',
        'daily_session_id',
        'speaking_state',
        'audio_granted',
        'video_granted',
        'screen_share_granted',
        'on_stage',
        'granted_by',
        'granted_at',
        'expires_at',
        'revoked_at',
        'meta',
    ];

    protected $casts = [
        'audio_granted' => 'boolean',
        'video_granted' => 'boolean',
        'screen_share_granted' => 'boolean',
        'on_stage' => 'boolean',
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => 'array',
    ];
}
