<?php

namespace App\Services\Billing;

use InvalidArgumentException;

final class BillingPlanCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array
    {
        $plans = config('billing.plans');

        if (! is_array($plans) || $plans === []) {
            throw new InvalidArgumentException('Billing plans configuration must be a non-empty array.');
        }

        $normalized = [];

        foreach ($plans as $code => $plan) {
            if (! is_string($code) || $code === '' || ! is_array($plan)) {
                throw new InvalidArgumentException('Billing plans must be keyed by non-empty plan codes.');
            }

            $normalized[$code] = [
                ...$plan,
                'code' => $code,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function requirePlan(string $code): array
    {
        $plan = $this->plan($code);

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown billing plan [{$code}].");
        }

        return $plan;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        $plans = $this->plans();

        return $plans[$code] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function planForStripePrice(?string $priceId): ?array
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach ($this->plans() as $plan) {
            if (($plan['stripe_price_id'] ?? null) === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicPlans(): array
    {
        return array_values($this->plans());
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function monthlyMinutes(array $plan): int
    {
        $minutes = $plan['monthly_minutes'] ?? null;

        if (! is_numeric($minutes) || (int) $minutes < 1) {
            throw new InvalidArgumentException("Billing plan [{$this->planCode($plan)}] must define positive monthly minutes.");
        }

        return (int) $minutes;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function generationTier(array $plan): string
    {
        $tier = $plan['generation_tier'] ?? null;

        if (! is_string($tier) || $tier === '') {
            throw new InvalidArgumentException("Billing plan [{$this->planCode($plan)}] must define a generation tier.");
        }

        return $tier;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function concurrency(array $plan): int
    {
        $concurrency = $plan['concurrency'] ?? null;

        if (! is_numeric($concurrency) || (int) $concurrency < 1) {
            throw new InvalidArgumentException("Billing plan [{$this->planCode($plan)}] must define positive concurrency.");
        }

        return (int) $concurrency;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function name(array $plan): string
    {
        $name = $plan['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException("Billing plan [{$this->planCode($plan)}] must define a display name.");
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function speedLabel(array $plan): string
    {
        $label = $plan['speed_label'] ?? null;

        if (! is_string($label) || $label === '') {
            throw new InvalidArgumentException("Billing plan [{$this->planCode($plan)}] must define a speed label.");
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function hasFeature(array $plan, string $feature): bool
    {
        $features = $plan['features'] ?? null;

        if (! is_array($features) || ! array_key_exists($feature, $features)) {
            throw new InvalidArgumentException("Billing plan [{$this->planCode($plan)}] must define feature [{$feature}].");
        }

        return (bool) $features[$feature];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function planCode(array $plan): string
    {
        $code = $plan['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : 'unknown';
    }
}
