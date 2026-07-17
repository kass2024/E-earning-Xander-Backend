<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingBreakoutRoom extends Model
{
    protected $fillable = [
        'meeting_key',
        'name',
        'daily_room_name',
        'daily_room_url',
        'status',
        'sort_order',
        'assigned_session_ids',
        'meta',
    ];

    protected $casts = [
        'assigned_session_ids' => 'array',
        'meta' => 'array',
    ];
}
