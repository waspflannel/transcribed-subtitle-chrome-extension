<?php

namespace App\Services\Billing;

use App\Models\StripeWebhookEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StripeWebhookService
{
    public function __construct(
        private readonly BillingPlanCatalog $plans,
        private readonly UsageLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event, string $payload): void
    {
        $eventId = data_get($event, 'id');
        $type = data_get($event, 'type');

        if (! is_string($eventId) || $eventId === '' || ! is_string($type) || $type === '') {
            return;
        }

        DB::transaction(function () use ($event, $payload, $eventId, $type): void {
            $record = StripeWebhookEvent::query()
                ->where('stripe_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($record instanceof StripeWebhookEvent && $record->processed_at !== null) {
                return;
            }

            $record ??= StripeWebhookEvent::create([
                'stripe_event_id' => $eventId,
                'type' => $type,
                'livemode' => (bool) data_get($event, 'livemode', false),
                'payload_hash' => hash('sha256', $payload),
            ]);

            try {
                $this->apply($event);
                $record->forceFill([
                    'processed_at' => now(),
                    'processing_error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $record->forceFill([
                    'processing_error' => $exception->getMessage(),
                ])->save();

                throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function apply(array $event): void
    {
        $type = data_get($event, 'type');
        $object = data_get($event, 'data.object');

        if (! is_array($object)) {
            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionChanged($object),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function handleCheckoutCompleted(array $session): void
    {
        $user = $this->findUserForObject($session);

        if (! $user instanceof User) {
            return;
        }

        $plan = $this->planForObject($session, $user);
        $subscriptionId = data_get($session, 'subscription');
        $customerId = data_get($session, 'customer');

        $user->forceFill([
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'billing_plan_code' => is_array($plan) ? (string) $plan['code'] : $user->billing_plan_code,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function handleSubscriptionChanged(array $subscription): void
    {
        $user = $this->findUserForObject($subscription);

        if (! $user instanceof User) {
            return;
        }

        $plan = $this->planForObject($subscription, $user);
        $periodStart = $this->timestamp(data_get($subscription, 'current_period_start'));
        $periodEnd = $this->timestamp(data_get($subscription, 'current_period_end'));
        $status = data_get($subscription, 'status');
        $subscriptionId = data_get($subscription, 'id');
        $subscriptionItemId = data_get($subscription, 'items.data.0.id');
        $customerId = data_get($subscription, 'customer');
        $endedAt = $this->timestamp(data_get($subscription, 'ended_at'))
            ?? $this->timestamp(data_get($subscription, 'canceled_at'));
        $trialEndsAt = $this->timestamp(data_get($subscription, 'trial_end'));

        $user->forceFill([
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'stripe_subscription_item_id' => is_string($subscriptionItemId) ? $subscriptionItemId : $user->stripe_subscription_item_id,
            'billing_plan_code' => is_array($plan) ? (string) $plan['code'] : $user->billing_plan_code,
            'billing_subscription_status' => is_string($status) ? $status : $user->billing_subscription_status,
            'billing_current_period_start' => $periodStart ?? $user->billing_current_period_start,
            'billing_current_period_end' => $periodEnd ?? $user->billing_current_period_end,
            'billing_cancel_at_period_end' => (bool) data_get($subscription, 'cancel_at_period_end', false),
            'billing_trial_ends_at' => $trialEndsAt,
            'billing_ends_at' => $endedAt,
        ])->save();

        if (is_array($plan) && in_array($user->billing_subscription_status, ['active', 'trialing'], true) && $periodStart !== null && $periodEnd !== null) {
            $this->ledger->ensureMonthlyGrant($user->refresh(), $plan, $periodStart, $periodEnd);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function handleInvoicePaymentFailed(array $invoice): void
    {
        $user = $this->findUserForObject($invoice);

        if (! $user instanceof User) {
            return;
        }

        $subscriptionId = data_get($invoice, 'subscription');

        $user->forceFill([
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'billing_subscription_status' => 'past_due',
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findUserForObject(array $object): ?User
    {
        $userId = data_get($object, 'metadata.user_id') ?? data_get($object, 'client_reference_id');

        if (is_numeric($userId)) {
            $user = User::query()->find((int) $userId);

            if ($user instanceof User) {
                return $user;
            }
        }

        $customerId = data_get($object, 'customer');

        if (is_string($customerId) && $customerId !== '') {
            return User::query()
                ->where('stripe_customer_id', $customerId)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function planForObject(array $object, User $user): ?array
    {
        $priceId = data_get($object, 'items.data.0.price.id');
        $plan = $this->plans->planForStripePrice(is_string($priceId) ? $priceId : null);

        if ($plan !== null) {
            return $plan;
        }

        $planCode = data_get($object, 'metadata.plan_code');

        if (is_string($planCode)) {
            return $this->plans->plan($planCode);
        }

        return $this->plans->plan($user->billing_plan_code);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $value);
    }
}
