<?php

namespace App\Services\Billing;

use App\Models\StripeWebhookEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        private readonly BillingPlanCatalog $plans,
        private readonly UsageLedger $ledger,
        private readonly StripeClient $stripe,
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
            'checkout.session.completed' => $this->handleCheckoutCompleted($object, $eventCreatedAt),
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
    private function handleCheckoutCompleted(array $session, ?CarbonImmutable $eventCreatedAt): void
    {
        $user = $this->lockedUserForObject($session);
        $subscriptionId = data_get($session, 'subscription');
        $matchesCheckoutIntent = $this->matchesCurrentCheckoutIntent($user, $session);

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new RuntimeException('Completed Stripe checkout did not include a subscription id.');
        }

        if (
            is_string($subscriptionId)
            && $subscriptionId !== ''
            && is_string($user->stripe_subscription_id)
            && $user->stripe_subscription_id !== ''
            && $user->stripe_subscription_id !== $subscriptionId
            && ! $matchesCheckoutIntent
        ) {
            return;
        }

        $this->applySubscriptionState(
            $user,
            $this->stripe->retrieveSubscription($subscriptionId),
            $eventCreatedAt,
            'checkout.session.completed',
        );
        $this->clearCheckoutIntentIfMatched($user, $session);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function handleSubscriptionChanged(
        array $subscription,
        ?CarbonImmutable $eventCreatedAt,
        string $eventType,
    ): void {
        // Account deletion cancels the subscription in Stripe and then removes the
        // user, so the trailing deleted event has no local user left to update.
        if ($eventType === 'customer.subscription.deleted' && $this->userForObject($subscription) === null) {
            Log::info('backend.stripe_subscription_deleted_without_local_user', [
                'stripe_customer_id' => data_get($subscription, 'customer'),
            ]);

            return;
        }

        $user = $this->lockedUserForObject($subscription);

        $subscriptionId = data_get($subscription, 'id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new RuntimeException('Stripe subscription webhook did not include a subscription id.');
        }

        $currentSubscriptionId = $user->stripe_subscription_id;
        $sameSubscription = ! is_string($currentSubscriptionId)
            || $currentSubscriptionId === ''
            || $currentSubscriptionId === $subscriptionId;
        $matchesCheckoutIntent = $this->matchesCurrentCheckoutIntent($user, $subscription);

        if (! $sameSubscription && ! $matchesCheckoutIntent) {
            return;
        }

        $ordering = $sameSubscription
            ? $this->subscriptionEventOrdering($user, $eventCreatedAt)
            : 'authoritative';

        if ($ordering === 'ignore') {
            return;
        }

        $state = $ordering === 'authoritative'
            ? $this->stripe->retrieveSubscription($subscriptionId)
            : $subscription;

        $this->applySubscriptionState($user, $state, $eventCreatedAt, $eventType);

        if ($matchesCheckoutIntent) {
            $this->clearCheckoutIntent($user);
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function applySubscriptionState(
        User $user,
        array $subscription,
        ?CarbonImmutable $eventCreatedAt,
        ?string $eventType,
    ): void {
        $subscriptionId = data_get($subscription, 'id');

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new RuntimeException('Stripe subscription response did not include a subscription id.');
        }

        $plan = $this->requirePlanForObject($subscription);
        $items = data_get($subscription, 'items.data');

        if (! is_array($items) || count($items) !== 1 || data_get($subscription, 'items.has_more', false)) {
            throw new RuntimeException('Stripe subscription must contain exactly one billing item.');
        }

        $periodStart = $this->timestamp(data_get($subscription, 'items.data.0.current_period_start')
            ?? data_get($subscription, 'current_period_start'));
        $periodEnd = $this->timestamp(data_get($subscription, 'items.data.0.current_period_end')
            ?? data_get($subscription, 'current_period_end'));

        if ($periodStart === null || $periodEnd === null || $periodEnd->lte($periodStart)) {
            throw new RuntimeException('Stripe subscription did not include a valid billing period.');
        }

        $status = data_get($subscription, 'status');
        $subscriptionItemId = data_get($subscription, 'items.data.0.id');
        $customerId = data_get($subscription, 'customer');
        $sameSubscription = $user->stripe_subscription_id === $subscriptionId;
        $previousEventAt = $sameSubscription ? $user->billing_subscription_event_at : null;
        $previousEventType = $sameSubscription ? $user->billing_subscription_event_type : null;
        $advanceWatermark = $eventCreatedAt !== null
            && ($previousEventAt === null || $eventCreatedAt->gte($previousEventAt));

        $user->forceFill([
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_subscription_item_id' => is_string($subscriptionItemId) ? $subscriptionItemId : $user->stripe_subscription_item_id,
            'billing_plan_code' => (string) $plan['code'],
            'billing_subscription_status' => is_string($status) ? $status : $user->billing_subscription_status,
            'billing_current_period_start' => $periodStart ?? $user->billing_current_period_start,
            'billing_current_period_end' => $periodEnd ?? $user->billing_current_period_end,
            'billing_cancel_at_period_end' => (bool) data_get($subscription, 'cancel_at_period_end', false),
            'billing_subscription_event_at' => $advanceWatermark ? $eventCreatedAt : $previousEventAt,
            'billing_subscription_event_type' => ! $advanceWatermark || $eventType === null
                ? $previousEventType
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
        $user = $this->lockedUserForObject($invoice);
        $subscriptionId = data_get($invoice, 'parent.subscription_details.subscription')
            ?? data_get($invoice, 'subscription');

        if (
            ! is_string($subscriptionId)
            || $subscriptionId === ''
            || $subscriptionId !== $user->stripe_subscription_id
        ) {
            return;
        }

        $this->applySubscriptionState(
            $user,
            $this->stripe->retrieveSubscription($subscriptionId),
            null,
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function requireUserForObject(array $object): User
    {
        $user = $this->userForObject($object);

        if (! $user instanceof User) {
            throw new RuntimeException('Stripe webhook did not match a local user.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function userForObject(array $object): ?User
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

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function lockedUserForObject(array $object): User
    {
        $user = $this->requireUserForObject($object);

        return User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->firstOrFail();
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

    private function subscriptionEventOrdering(User $user, ?CarbonImmutable $eventCreatedAt): string
    {
        if ($eventCreatedAt === null || $user->billing_subscription_event_at === null) {
            return 'payload';
        }

        if ($eventCreatedAt->gt($user->billing_subscription_event_at)) {
            return 'payload';
        }

        if ($eventCreatedAt->lt($user->billing_subscription_event_at)) {
            return 'ignore';
        }

        return 'authoritative';
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function matchesCurrentCheckoutIntent(User $user, array $object): bool
    {
        $intentId = data_get($object, 'metadata.checkout_intent_id');

        return is_string($intentId)
            && $intentId !== ''
            && $intentId === $user->stripe_checkout_intent_id;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function clearCheckoutIntentIfMatched(User $user, array $session): void
    {
        $sessionId = data_get($session, 'id');

        if (
            $this->matchesCurrentCheckoutIntent($user, $session)
            || (is_string($sessionId) && $sessionId !== '' && $sessionId === $user->stripe_checkout_session_id)
        ) {
            $this->clearCheckoutIntent($user);
        }
    }

    private function clearCheckoutIntent(User $user): void
    {
        $user->forceFill([
            'stripe_checkout_intent_id' => null,
            'stripe_checkout_plan_code' => null,
            'stripe_checkout_session_id' => null,
            'stripe_checkout_session_url' => null,
            'stripe_checkout_expires_at' => null,
        ])->save();
    }
}
