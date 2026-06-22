<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_subscription_item_id',
        'billing_plan_code',
        'billing_subscription_status',
        'billing_current_period_start',
        'billing_current_period_end',
        'billing_cancel_at_period_end',
        'billing_trial_ends_at',
        'billing_ends_at',
        'billing_subscription_event_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function subtitleJobs(): HasMany
    {
        return $this->hasMany(SubtitleJob::class);
    }

    public function billingUsageEvents(): HasMany
    {
        return $this->hasMany(BillingUsageEvent::class);
    }

    protected function casts(): array
    {
        return [
            'billing_cancel_at_period_end' => 'boolean',
            'billing_current_period_end' => 'immutable_datetime',
            'billing_current_period_start' => 'immutable_datetime',
            'billing_current_period_end' => 'immutable_datetime',
            'billing_ends_at' => 'immutable_datetime',
            'billing_subscription_event_at' => 'immutable_datetime',
            'billing_trial_ends_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }
}
