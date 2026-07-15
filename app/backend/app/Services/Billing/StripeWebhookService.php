<?php

namespace App\Services\Billing;

use App\Models\StripeWebhookEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class StripeWebhookService
{
    private const HANDLED_EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_failed',
    ];

    private const SUBSCRIPTION_EVENT_PRECEDENCE = [
        'customer.subscription.created' => 1,
        'customer.subscription.updated' => 2,
        'customer.subscription.deleted' => 3,
    ];

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
            throw new RuntimeException('Stripe webhook event id and type are required.');
        }

        try {
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

                $this->apply($event, $this->eventCreatedAt($event), $type);
                $record->forceFill([
                    'processed_at' => now(),
                    'processing_error' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            StripeWebhookEvent::query()->updateOrCreate(
                ['stripe_event_id' => $eventId],
                [
                    'type' => $type,
                    'livemode' => (bool) data_get($event, 'livemode', false),
                    'payload_hash' => hash('sha256', $payload),
                    'processing_error' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function apply(array $event, ?CarbonImmutable $eventCreatedAt, string $type): void
    {
        if (! in_array($type, self::HANDLED_EVENTS, true)) {
            return;
        }

        $object = data_get($event, 'data.object');

        if (! is_array($object)) {
            throw new RuntimeException("Stripe webhook [{$type}] is missing data.object.");
        }

        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionChanged($object, $eventCreatedAt, $type),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function handleCheckoutCompleted(array $session): void
    {
        $user = $this->requireUserForObject($session);
        $plan = $this->requirePlanForObject($session);
        $subscriptionId = data_get($session, 'subscription');
        $customerId = data_get($session, 'customer');

        if (
            is_string($subscriptionId)
            && $subscriptionId !== ''
            && is_string($user->stripe_subscription_id)
            && $user->stripe_subscription_id !== ''
            && $user->stripe_subscription_id !== $subscriptionId
            && ! $this->subscriptionIsTerminal($user)
        ) {
            return;
        }

        $user->forceFill([
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'billing_plan_code' => is_array($plan) ? (string) $plan['code'] : $user->billing_plan_code,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function handleSubscriptionChanged(
        array $subscription,
        ?CarbonImmutable $eventCreatedAt,
        string $eventType,
    ): void {
        $user = $this->requireUserForObject($subscription);

        $user = User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->first() ?? $user;

        $subscriptionId = data_get($subscription, 'id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new RuntimeException('Stripe subscription webhook did not include a subscription id.');
        }

        if (
            is_string($user->stripe_subscription_id)
            && $user->stripe_subscription_id !== ''
            && $user->stripe_subscription_id !== $subscriptionId
        ) {
            return;
        }

        if (! $this->subscriptionEventShouldApply($user, $eventCreatedAt, $eventType)) {
            return;
        }

        $plan = $this->requirePlanForObject($subscription);
        $periodStart = $this->timestamp(data_get($subscription, 'current_period_start'));
        $periodEnd = $this->timestamp(data_get($subscription, 'current_period_end'));
        $status = data_get($subscription, 'status');
        $subscriptionItemId = data_get($subscription, 'items.data.0.id');
        $customerId = data_get($subscription, 'customer');
        $endedAt = $this->timestamp(data_get($subscription, 'ended_at'))
            ?? $this->timestamp(data_get($subscription, 'canceled_at'));
        $trialEndsAt = $this->timestamp(data_get($subscription, 'trial_end'));

        $user->forceFill([
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_subscription_item_id' => is_string($subscriptionItemId) ? $subscriptionItemId : $user->stripe_subscription_item_id,
            'billing_plan_code' => (string) $plan['code'],
            'billing_subscription_status' => is_string($status) ? $status : $user->billing_subscription_status,
            'billing_current_period_start' => $periodStart ?? $user->billing_current_period_start,
            'billing_current_period_end' => $periodEnd ?? $user->billing_current_period_end,
            'billing_cancel_at_period_end' => (bool) data_get($subscription, 'cancel_at_period_end', false),
            'billing_trial_ends_at' => $trialEndsAt,
            'billing_ends_at' => $endedAt,
            'billing_subscription_event_at' => $eventCreatedAt ?? $user->billing_subscription_event_at,
            'billing_subscription_event_type' => $eventCreatedAt === null
                ? $user->billing_subscription_event_type
                : $eventType,
        ])->save();

        if (in_array($user->billing_subscription_status, ['active', 'trialing'], true) && $periodStart !== null && $periodEnd !== null) {
            $this->ledger->ensureMonthlyGrant($user->refresh(), $plan, $periodStart, $periodEnd);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function handleInvoicePaymentFailed(array $invoice): void
    {
        $user = $this->requireUserForObject($invoice);
        $subscriptionId = data_get($invoice, 'subscription');

        if (
            ! is_string($subscriptionId)
            || $subscriptionId === ''
            || $subscriptionId !== $user->stripe_subscription_id
        ) {
            return;
        }

        $user->forceFill([
            'billing_subscription_status' => 'past_due',
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function requireUserForObject(array $object): User
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
            $user = User::query()
                ->where('stripe_customer_id', $customerId)
                ->first();

            if ($user instanceof User) {
                return $user;
            }
        }

        throw new RuntimeException('Stripe webhook did not match a local user.');
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function requirePlanForObject(array $object): array
    {
        $priceId = data_get($object, 'items.data.0.price.id');
        $plan = $this->plans->planForStripePrice(is_string($priceId) ? $priceId : null);

        if ($plan !== null) {
            return $plan;
        }

        $planCode = data_get($object, 'metadata.plan_code');

        if (is_string($planCode) && $planCode !== '') {
            return $this->plans->requirePlan($planCode);
        }

        throw new RuntimeException('Stripe webhook did not include a configured billing plan.');
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $value);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventCreatedAt(array $event): ?CarbonImmutable
    {
        return $this->timestamp(data_get($event, 'created'));
    }

    private function subscriptionEventShouldApply(
        User $user,
        ?CarbonImmutable $eventCreatedAt,
        string $eventType,
    ): bool {
        if ($eventCreatedAt === null || $user->billing_subscription_event_at === null) {
            return true;
        }

        if ($eventCreatedAt->gt($user->billing_subscription_event_at)) {
            return true;
        }

        if ($eventCreatedAt->lt($user->billing_subscription_event_at)) {
            return false;
        }

        return $this->subscriptionEventPrecedence($eventType)
            > $this->subscriptionEventPrecedence($user->billing_subscription_event_type);
    }

    private function subscriptionEventPrecedence(?string $eventType): int
    {
        return self::SUBSCRIPTION_EVENT_PRECEDENCE[$eventType] ?? 0;
    }

    private function subscriptionIsTerminal(User $user): bool
    {
        return in_array($user->billing_subscription_status, ['canceled', 'incomplete_expired'], true);
    }
}
