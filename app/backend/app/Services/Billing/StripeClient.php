<?php

namespace App\Services\Billing;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class StripeClient
{
    public function createCustomer(User $user): string
    {
        $response = $this->request()
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
        $response = $this->request()
            ->asForm()
            ->post('/checkout/sessions', [
                'mode' => 'subscription',
                'customer' => $customerId,
                'client_reference_id' => (string) $user->id,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan_code' => (string) $plan['code'],
                ],
                'subscription_data' => [
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'plan_code' => (string) $plan['code'],
                    ],
                ],
            ])
            ->throw();

        return $this->sessionResponse($response->json());
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

    private function customerIdFor(User $user): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
        }

        $customerId = $this->createCustomer($user);
        $user->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
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
}
