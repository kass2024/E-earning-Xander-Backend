<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingPoll extends Model
{
    protected $fillable = [
        'meeting_key',
        'created_by',
        'question',
        'options',
        'status',
        'allow_multiple',
        'show_results',
        'opened_at',
        'closed_at',
        'meta',
    ];

    protected $casts = [
        'options' => 'array',
        'allow_multiple' => 'boolean',
        'show_results' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function votes(): HasMany
    {
        return $this->hasMany(MeetingPollVote::class, 'poll_id');
    }
}
