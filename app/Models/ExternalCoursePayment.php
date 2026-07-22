<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCoursePayment extends Model
{
    protected $fillable = [
        'course_id',
        'course_title',
        'course_price_rwf',
        'amount_rwf',
        'currency',
        'payer_name',
        'payer_email',
        'payer_phone',
        'msisdn',
        'mno',
        'external_reference',
        'provider',
        'status',
        'receipt_path',
        'receipt_emailed',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'receipt_emailed' => 'boolean',
        'paid_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
