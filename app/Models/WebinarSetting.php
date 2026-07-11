<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebinarSetting extends Model
{
    protected $fillable = [
        'platform_institution_id',
        'recording_enabled',
        'zoom_meeting_id',
        'zoom_password',
        'zoom_join_url',
        'zoom_start_url',
        'zoom_scheduled_at',
        'zoom_host_user_id',
        'session_started_at',
        'calendar_blocked_months',
        'calendar_blocked_dates',
    ];

    protected $casts = [
        'recording_enabled' => 'boolean',
        'zoom_scheduled_at' => 'datetime',
        'session_started_at' => 'datetime',
        'calendar_blocked_months' => 'array',
        'calendar_blocked_dates' => 'array',
    ];

    /** Platform-wide singleton (legacy Pathways webinar). */
    public static function current(): self
    {
        return static::forInstitution(null);
    }

    public static function forInstitution(?int $platformInstitutionId = null): self
    {
        $query = static::query();
        if ($platformInstitutionId) {
            $query->where('platform_institution_id', $platformInstitutionId);
        } else {
            $query->whereNull('platform_institution_id');
        }

        return $query->firstOrCreate(
            ['platform_institution_id' => $platformInstitutionId],
            ['recording_enabled' => false]
        );
    }
}
