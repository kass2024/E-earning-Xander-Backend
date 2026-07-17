<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingQaItem extends Model
{
    protected $fillable = [
        'meeting_key',
        'user_id',
        'daily_session_id',
        'author_name',
        'question',
        'answer',
        'status',
        'is_anonymous',
        'upvotes',
        'answered_by',
        'answered_at',
        'meta',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'answered_at' => 'datetime',
        'meta' => 'array',
    ];
}
