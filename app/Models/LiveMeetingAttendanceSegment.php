<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveMeetingAttendanceSegment extends Model
{
    protected $fillable = [
        'platform_institution_id',
        'course_id',
        'course_material_id',
        'user_id',
        'provider',
        'provider_session_id',
        'provider_participant_id',
        'provider_user_id',
        'role',
        'source',
        'joined_at',
        'left_at',
        'duration_seconds',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function courseMaterial(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
