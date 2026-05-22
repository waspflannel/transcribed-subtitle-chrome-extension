<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingUsageEvent extends Model
{
    protected $fillable = [
        'user_id',
        'subtitle_job_id',
        'subtitle_track_id',
        'stripe_subscription_id',
        'plan_code',
        'event_type',
        'billing_period_start',
        'billing_period_end',
        'minutes',
        'available_minutes_delta',
        'reserved_minutes_delta',
        'used_minutes_delta',
        'provider_cost_microusd_delta',
        'idempotency_key',
        'created_by',
        'note',
        'metadata',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subtitleJob(): BelongsTo
    {
        return $this->belongsTo(SubtitleJob::class);
    }

    public function subtitleTrack(): BelongsTo
    {
        return $this->belongsTo(SubtitleTrack::class);
    }

    protected function casts(): array
    {
        return [
            'available_minutes_delta' => 'integer',
            'billing_period_end' => 'immutable_datetime',
            'billing_period_start' => 'immutable_datetime',
            'metadata' => 'array',
            'minutes' => 'integer',
            'provider_cost_microusd_delta' => 'integer',
            'reserved_minutes_delta' => 'integer',
            'used_minutes_delta' => 'integer',
        ];
    }
}
