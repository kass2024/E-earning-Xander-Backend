<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'external_event_id',
        'event_type',
        'received_at',
        'processed_at',
        'processing_status',
        'attempts',
        'last_error',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
