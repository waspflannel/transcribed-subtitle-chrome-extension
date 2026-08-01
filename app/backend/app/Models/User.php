<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_subscription_item_id',
        'stripe_checkout_intent_id',
        'stripe_checkout_plan_code',
        'stripe_checkout_session_id',
        'stripe_checkout_session_url',
        'stripe_checkout_expires_at',
        'billing_plan_code',
        'billing_subscription_status',
        'billing_current_period_start',
        'billing_current_period_end',
        'billing_cancel_at_period_end',
        'billing_subscription_event_at',
        'billing_subscription_event_type',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'stripe_checkout_intent_id',
        'stripe_checkout_session_url',
    ];

    public function subtitleJobs(): HasMany
    {
        return $this->hasMany(SubtitleJob::class);
    }

    protected function casts(): array
    {
        return [
            'billing_cancel_at_period_end' => 'boolean',
            'billing_current_period_end' => 'immutable_datetime',
            'billing_current_period_start' => 'immutable_datetime',
            'billing_subscription_event_at' => 'immutable_datetime',
            'password' => 'hashed',
            'stripe_checkout_expires_at' => 'immutable_datetime',
        ];
    }
}
