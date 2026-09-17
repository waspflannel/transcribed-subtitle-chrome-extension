<?php

namespace App\Services\Billing;

use App\Exceptions\StripeCheckoutPendingException;
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

        return DB::transaction(function () use ($user, $plan, $priceId, $customerId, $intent, $successUrl, $cancelUrl): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->ensureCheckoutAllowed($lockedUser);

            if ($lockedUser->stripe_checkout_intent_id !== $intent['id']) {
                throw new RuntimeException('Checkout intent changed before session creation.');
            }

            if (is_string($lockedUser->stripe_checkout_session_id) && is_string($lockedUser->stripe_checkout_session_url)) {
                return ['id' => $lockedUser->stripe_checkout_session_id, 'url' => $lockedUser->stripe_checkout_session_url];
            }

            $response = $this->request()
                ->withHeaders(['Idempotency-Key' => $this->idempotencyKey('checkout', $lockedUser, $intent['id'])])
                ->asForm()
                ->post('/checkout/sessions', [
                    'mode' => 'subscription',
                    'customer' => $customerId,
                    'client_reference_id' => (string) $lockedUser->id,
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
                        'user_id' => (string) $lockedUser->id,
                        'plan_code' => (string) $plan['code'],
                        'checkout_intent_id' => $intent['id'],
                    ],
                    'subscription_data' => [
                        'metadata' => [
                            'user_id' => (string) $lockedUser->id,
                            'plan_code' => (string) $plan['code'],
                            'checkout_intent_id' => $intent['id'],
                        ],
                    ],
                ])
                ->throw();

            $session = $this->sessionResponse($response->json());

            $lockedUser->forceFill([
                'stripe_checkout_session_id' => $session['id'],
                'stripe_checkout_session_url' => $session['url'],
            ])->save();

            return $session;
        }, attempts: 5);
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createBillingPortalSession(User $user, string $returnUrl): array
    {
        $configuration = config('billing.stripe.portal_configuration');

        $customerId = $this->customerIdFor($user);
        $payload = [
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ];

        if (is_string($configuration) && trim($configuration) !== '') {
            $payload['configuration'] = trim($configuration);
        }

        $response = $this->request()
            ->asForm()
            ->post('/billing_portal/sessions', $payload)
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

            $this->ensureCheckoutAllowed($lockedUser);

            if ($lockedUser->stripe_checkout_intent_id !== null
                && $lockedUser->stripe_checkout_session_id === null
                && ($lockedUser->stripe_checkout_plan_code !== $planCode
                    || $lockedUser->stripe_checkout_expires_at?->lte(now()->addMinutes(30)))) {
                $this->reconcileUnknownCheckout($lockedUser);
            }

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

            $this->expireOutstandingCheckout($lockedUser);

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

    private function ensureCheckoutAllowed(User $user): void
    {
        if (is_string($user->billing_subscription_status)
            && $user->billing_subscription_status !== ''
            && ! in_array($user->billing_subscription_status, ['canceled', 'incomplete_expired'], true)) {
            throw new RuntimeException('An existing subscription must be managed in the billing portal.');
        }
    }

    /** The caller must hold the user row lock until replacement or account deletion commits. */
    public function expireOutstandingCheckout(User $user): void
    {
        if ($user->stripe_checkout_intent_id !== null && $user->stripe_checkout_session_id === null) {
            $this->reconcileUnknownCheckout($user);
        }

        if (is_string($user->stripe_checkout_session_id) && $user->stripe_checkout_session_id !== '') {
            $this->expireCheckoutSession($user->stripe_checkout_session_id);
        }
    }

    /** Recover identity before replacing a request whose response was lost. Caller holds the user lock. */
    private function reconcileUnknownCheckout(User $user): void
    {
        if (! is_string($user->stripe_customer_id) || $user->stripe_customer_id === ''
            || $user->stripe_checkout_expires_at === null) {
            throw new RuntimeException('Previous checkout is missing its reconciliation identity.');
        }

        $matchingSession = null;
        $cursor = null;
        $seenCursors = [];

        do {
            $page = $this->request()->get('/checkout/sessions', array_filter([
                'customer' => $user->stripe_customer_id,
                'limit' => 100,
                'starting_after' => $cursor,
            ], static fn ($value): bool => $value !== null))->throw()->json();

            if (! is_array($page) || ! is_array($page['data'] ?? null)
                || ! array_is_list($page['data']) || ! is_bool($page['has_more'] ?? null)) {
                throw new RuntimeException('Stripe checkout lookup returned an invalid page.');
            }

            foreach ($page['data'] as $session) {
                if (! is_array($session) || ! is_string($session['id'] ?? null)
                    || $session['id'] === '' || ($session['customer'] ?? null) !== $user->stripe_customer_id) {
                    throw new RuntimeException('Stripe checkout lookup returned an invalid session.');
                }

                if (data_get($session, 'metadata.checkout_intent_id') === $user->stripe_checkout_intent_id) {
                    if ($matchingSession !== null || ($session['mode'] ?? null) !== 'subscription') {
                        throw new RuntimeException('Previous checkout has an ambiguous reconciliation result.');
                    }

                    $matchingSession = $session;
                }
            }

            if ($page['has_more']) {
                $cursor = data_get($page, 'data.'.(count($page['data']) - 1).'.id');
                if (! is_string($cursor) || in_array($cursor, $seenCursors, true)) {
                    throw new RuntimeException('Stripe checkout lookup returned an invalid cursor.');
                }
                $seenCursors[] = $cursor;
            }
        } while ($page['has_more']);

        if ($matchingSession !== null) {
            $status = $matchingSession['status'] ?? null;

            if ($status === 'open') {
                $session = $this->sessionResponse($matchingSession);
                $user->forceFill([
                    'stripe_checkout_session_id' => $session['id'],
                    'stripe_checkout_session_url' => $session['url'],
                ])->save();

                return;
            }

            if ($status === 'complete') {
                throw new StripeCheckoutPendingException('Your previous checkout has completed. Wait for your billing status to update, then try again. Contact support if it does not update.');
            }

            if ($status !== 'expired') {
                throw new RuntimeException('Previous checkout has an unknown status.');
            }
        } elseif ($user->stripe_checkout_expires_at->isFuture()) {
            throw new StripeCheckoutPendingException(
                'Your previous checkout could not be confirmed. Retry after :retryAt to start checkout or delete your account.',
                ['retryAt' => $user->stripe_checkout_expires_at->utc()->format('Y-m-d H:i:s \\U\\T\\C')],
            );
        }

        // A complete lookup plus the original absolute expiry prevents a lost request
        // from creating a payable session later. Never replay that expired intent.
        $user->forceFill([
            'stripe_checkout_intent_id' => null,
            'stripe_checkout_plan_code' => null,
            'stripe_checkout_session_id' => null,
            'stripe_checkout_session_url' => null,
            'stripe_checkout_expires_at' => null,
        ])->save();
    }

    private function expireCheckoutSession(string $sessionId): void
    {
        $path = '/checkout/sessions/'.rawurlencode($sessionId);
        $response = $this->request()->asForm()->post($path.'/expire');

        // Completion can win the expiration race. Only a confirmed expired session permits replacement.
        if ($response->clientError()) {
            $response = $this->request()->get($path);
        }

        $payload = $response->throw()->json();

        if (is_array($payload) && ($payload['id'] ?? null) === $sessionId && ($payload['status'] ?? null) === 'complete') {
            throw new StripeCheckoutPendingException('Your previous checkout has completed. Wait for your billing status to update, then try again. Contact support if it does not update.');
        }

        if (! is_array($payload) || ($payload['id'] ?? null) !== $sessionId || ($payload['status'] ?? null) !== 'expired') {
            throw new RuntimeException('Previous checkout is not confirmed expired; await billing reconciliation.');
        }
    }

    private function request(): PendingRequest
    {
        $secret = config('billing.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('STRIPE_SECRET is not configured.');
        }

        return Http::baseUrl((string) config('billing.stripe.api_base_url'))
            ->withHeaders(['Stripe-Version' => (string) config('billing.stripe.api_version')])
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
