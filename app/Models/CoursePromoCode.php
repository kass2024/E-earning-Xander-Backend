<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePromoCode extends Model
{
    protected $fillable = [
        'code',
        'label',
        'max_uses',
        'uses_count',
        'is_active',
        'expires_at',
        'course_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function isRedeemable(?int $courseId = null): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->uses_count >= $this->max_uses) {
            return false;
        }

        if ($this->course_id && $courseId && (int) $this->course_id !== (int) $courseId) {
            return false;
        }

        return true;
    }
}
