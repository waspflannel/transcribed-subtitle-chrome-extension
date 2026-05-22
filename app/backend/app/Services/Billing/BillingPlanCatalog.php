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
        $plans = config('billing.plans', []);

        if (! is_array($plans)) {
            return [];
        }

        $normalized = [];

        foreach ($plans as $code => $plan) {
            if (! is_string($code) || ! is_array($plan)) {
                continue;
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
        return max(0, (int) ($plan['monthly_minutes'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function generationTier(array $plan): string
    {
        $tier = $plan['generation_tier'] ?? 'base';

        return is_string($tier) && $tier !== '' ? $tier : 'base';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function concurrency(array $plan): int
    {
        return max(1, (int) ($plan['concurrency'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function name(array $plan): string
    {
        $name = $plan['name'] ?? $plan['code'] ?? 'Plan';

        return is_string($name) && $name !== '' ? $name : 'Plan';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function speedLabel(array $plan): string
    {
        $label = $plan['speed_label'] ?? 'Standard queue';

        return is_string($label) && $label !== '' ? $label : 'Standard queue';
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function hasFeature(array $plan, string $feature): bool
    {
        return (bool) data_get($plan, "features.{$feature}", false);
    }
}
