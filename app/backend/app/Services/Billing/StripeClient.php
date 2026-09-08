<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class StripeClient
{
    public function createCustomer(User $user): string
    {
        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey('customer', $user)])
            ->asForm()
            ->post('/customers', [
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => (string) $user->id,
                ],
            ])
            ->throw();

        $customerId = $response->json('id');

        if (! is_string($customerId) || $customerId === '') {
            throw new RuntimeException('Stripe customer response did not include an id.');
        }

        return $customerId;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(
        User $user,
        array $plan,
        string $successUrl,
        string $cancelUrl,
    ): array {
        $priceId = $plan['stripe_price_id'] ?? null;

        if (! is_string($priceId) || $priceId === '') {
            throw new RuntimeException('Stripe price id is not configured for this billing plan.');
        }

        $customerId = $this->customerIdFor($user);
        $intent = $this->checkoutIntentFor($user, (string) $plan['code']);

        if ($intent['session'] !== null) {
            return $intent['session'];
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey('checkout', $user, $intent['id'])])
            ->asForm()
            ->post('/checkout/sessions', [
                'mode' => 'subscription',
                'customer' => $customerId,
                'client_reference_id' => (string) $user->id,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'expires_at' => $intent['expiresAt'],
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan_code' => (string) $plan['code'],
                    'checkout_intent_id' => $intent['id'],
                ],
                'subscription_data' => [
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'plan_code' => (string) $plan['code'],
                        'checkout_intent_id' => $intent['id'],
                    ],
                ],
            ])
            ->throw();

        $session = $this->sessionResponse($response->json());
        $this->storeCheckoutSession($user, $intent['id'], $session);

        return $session;
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createBillingPortalSession(User $user, string $returnUrl): array
    {
        $customerId = $this->customerIdFor($user);
        $response = $this->request()
            ->asForm()
            ->post('/billing_portal/sessions', [
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ])
            ->throw();

        return $this->sessionResponse($response->json());
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            throw new RuntimeException('Stripe subscription id is required.');
        }

        $response = $this->request()
            ->delete('/subscriptions/'.rawurlencode($subscriptionId));

        // A 404 means the subscription is already gone in Stripe — nothing left to cancel.
        if ($response->notFound()) {
            return;
        }

        $response->throw();
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveSubscription(string $subscriptionId): array
    {
        if ($subscriptionId === '') {
            throw new RuntimeException('Stripe subscription id is required.');
        }

        $payload = $this->request()
            ->get('/subscriptions/'.rawurlencode($subscriptionId))
            ->throw()
            ->json();

        if (! is_array($payload) || ($payload['id'] ?? null) !== $subscriptionId) {
            throw new RuntimeException('Stripe subscription response did not include the requested id.');
        }

        return $payload;
    }

    private function customerIdFor(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (is_string($lockedUser->stripe_customer_id) && $lockedUser->stripe_customer_id !== '') {
                return $lockedUser->stripe_customer_id;
            }

            $customerId = $this->createCustomer($lockedUser);
            $lockedUser->forceFill(['stripe_customer_id' => $customerId])->save();

            return $customerId;
        }, attempts: 5);
    }

    /**
     * @return array{id: string, expiresAt: int, session: array{id: string, url: string}|null}
     */
    private function checkoutIntentFor(User $user, string $planCode): array
    {
        return DB::transaction(function () use ($user, $planCode): array {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                is_string($lockedUser->stripe_checkout_intent_id)
                && $lockedUser->stripe_checkout_intent_id !== ''
                && $lockedUser->stripe_checkout_plan_code === $planCode
                && $lockedUser->stripe_checkout_expires_at?->isFuture()
            ) {
                $session = is_string($lockedUser->stripe_checkout_session_id)
                    && $lockedUser->stripe_checkout_session_id !== ''
                    && is_string($lockedUser->stripe_checkout_session_url)
                    && $lockedUser->stripe_checkout_session_url !== ''
                    ? [
                        'id' => $lockedUser->stripe_checkout_session_id,
                        'url' => $lockedUser->stripe_checkout_session_url,
                    ]
                    : null;

                return [
                    'id' => $lockedUser->stripe_checkout_intent_id,
                    'expiresAt' => $lockedUser->stripe_checkout_expires_at->timestamp,
                    'session' => $session,
                ];
            }

            $intentId = (string) Str::uuid();
            $expiresAt = now()->addMinutes(31);

            $lockedUser->forceFill([
                'stripe_checkout_intent_id' => $intentId,
                'stripe_checkout_plan_code' => $planCode,
                'stripe_checkout_session_id' => null,
                'stripe_checkout_session_url' => null,
                'stripe_checkout_expires_at' => $expiresAt,
            ])->save();

            return [
                'id' => $intentId,
                'expiresAt' => $expiresAt->timestamp,
                'session' => null,
            ];
        }, attempts: 5);
    }

    /**
     * @param  array{id: string, url: string}  $session
     */
    private function storeCheckoutSession(User $user, string $intentId, array $session): void
    {
        DB::transaction(function () use ($user, $intentId, $session): void {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->stripe_checkout_intent_id !== $intentId) {
                return;
            }

            $lockedUser->forceFill([
                'stripe_checkout_session_id' => $session['id'],
                'stripe_checkout_session_url' => $session['url'],
            ])->save();
        }, attempts: 5);
    }

    private function request(): PendingRequest
    {
        $secret = config('billing.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }

        return Http::baseUrl((string) config('billing.stripe.api_base_url'))
            ->withToken($secret)
            ->acceptJson()
            ->timeout(max(1, (int) config('billing.stripe.timeout_seconds', 15)))
            ->connectTimeout(max(1, (int) config('billing.stripe.connect_timeout_seconds', 5)));
    }

    /**
     * @return array{id: string, url: string}
     */
    private function sessionResponse(mixed $payload): array
    {
        if (! is_array($payload) || ! is_string($payload['id'] ?? null) || ! is_string($payload['url'] ?? null)) {
            throw new RuntimeException('Stripe session response did not include an id and url.');
        }

        return [
            'id' => $payload['id'],
            'url' => $payload['url'],
        ];
    }

    private function idempotencyKey(string $operation, User $user, ?string $intentId = null): string
    {
        $scope = implode(':', array_filter([$operation, (string) $user->id, $intentId]));

        return 'tse-v1-'.hash('sha256', $scope);
    }
}
