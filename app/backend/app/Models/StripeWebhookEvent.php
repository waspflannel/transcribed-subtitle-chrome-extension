<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'stripe_event_id',
        'type',
        'livemode',
        'payload_hash',
        'processed_at',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
