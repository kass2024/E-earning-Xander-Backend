<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminZoomMeeting extends Model
{
    protected $fillable = [
        'zoom_meeting_id',
        'zoom_uuid',
        'topic',
        'start_time',
        'duration',
        'join_url',
        'password',
        'agenda',
        'created_by_user_id',
        'meta',
        'meeting_provider',
        'meeting_mode',
        'daily_room_name',
        'daily_room_url',
        'session_status',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'duration' => 'integer',
        'meta' => 'array',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toMeetingArray(): array
    {
        return array_filter([
            'id' => $this->zoom_meeting_id,
            'uuid' => $this->zoom_uuid,
            'topic' => $this->topic,
            'start_time' => $this->start_time?->toIso8601String(),
            'duration' => $this->duration,
            'join_url' => $this->join_url,
            'password' => $this->password,
            'agenda' => $this->agenda,
            'provider' => $this->meeting_provider ?: ($this->meta['meeting_provider'] ?? null),
            'meeting_mode' => $this->meeting_mode ?: ($this->meta['meeting_mode'] ?? $this->meta['type'] ?? null),
            'daily_room_name' => $this->daily_room_name,
            'session_status' => $this->session_status,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
