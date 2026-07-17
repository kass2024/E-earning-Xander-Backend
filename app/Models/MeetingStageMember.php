<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingStageMember extends Model
{
    protected $fillable = [
        'meeting_key',
        'daily_session_id',
        'user_id',
        'display_name',
        'stage_role',
        'sort_order',
        'spotlighted',
    ];

    protected $casts = [
        'spotlighted' => 'boolean',
    ];
}
