<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveZoomCohortQueueEntry extends Model
{
    protected $table = 'livezoom_cohort_queue_entries';

    protected $fillable = [
        'livezoom_cohort_id',
        'student_id',
        'guest_token',
        'guest_email',
        'guest_phone',
        'display_name',
        'status',
        'queue_position',
        'joined_at',
        'admitted_at',
        'attended_at',
        'released_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'admitted_at' => 'datetime',
        'attended_at' => 'datetime',
        'released_at' => 'datetime',
        'queue_position' => 'integer',
        'student_id' => 'integer',
    ];

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(LiveZoomCohort::class, 'livezoom_cohort_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
