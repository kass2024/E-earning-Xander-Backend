<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingPollVote extends Model
{
    protected $fillable = [
        'poll_id',
        'user_id',
        'daily_session_id',
        'option_index',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(MeetingPoll::class, 'poll_id');
    }
}
