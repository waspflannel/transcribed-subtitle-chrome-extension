<?php

namespace App\Services\Billing;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class TestingPlanSwitcher
{
    public const CLEAR_PLAN_CODE = 'none';

    public function __construct(
        private readonly BillingPlanCatalog $plans,
        private readonly UsageLedger $ledger,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('billing.testing_plan_switcher.enabled', false)
            && ! app()->isProduction();
    }

    /**
     * @return list<string>
     */
    public function selectablePlanCodes(): array
    {
        return [
            ...array_keys($this->plans->plans()),
            self::CLEAR_PLAN_CODE,
        ];
    }

    public function switchPlan(User $user, string $planCode): ?string
    {
        if ($planCode === self::CLEAR_PLAN_CODE) {
            $this->clear($user);

            return null;
        }

        $plan = $this->plans->requirePlan($planCode);
        $periodStart = now()->startOfMonth()->toImmutable();
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->toImmutable();

        DB::transaction(function () use ($user, $plan, $periodStart, $periodEnd): void {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->forceFill([
                'billing_plan_code' => (string) $plan['code'],
                'billing_subscription_status' => 'active',
                'billing_current_period_start' => $periodStart,
                'billing_current_period_end' => $periodEnd,
                'billing_cancel_at_period_end' => false,
                'billing_trial_ends_at' => null,
                'billing_ends_at' => null,
            ])->save();

            $lockedUser = $lockedUser->refresh();

            $this->ledger->ensureMonthlyGrant($lockedUser, $plan, $periodStart, $periodEnd);
            $this->rebalanceAvailableMinutes($lockedUser, $plan, $periodStart, $periodEnd);
        });

        return $this->plans->name($plan);
    }

    private function clear(User $user): void
    {
        User::query()
            ->whereKey($user->id)
            ->update([
                'billing_plan_code' => null,
                'billing_subscription_status' => null,
                'billing_current_period_start' => null,
                'billing_current_period_end' => null,
                'billing_cancel_at_period_end' => false,
                'billing_trial_ends_at' => null,
                'billing_ends_at' => null,
            ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function rebalanceAvailableMinutes(
        User $user,
        array $plan,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): void {
        $summary = $this->ledger->summary($user, $periodStart, $periodEnd);
        $targetAvailable = max(
            0,
            $this->plans->monthlyMinutes($plan) - $summary['used'] - $summary['reserved'],
        );
        $delta = $targetAvailable - $summary['available'];

        if ($delta === 0) {
            return;
        }

        $this->ledger->adjust(
            user: $user,
            minutesDelta: $delta,
            note: 'Testing billing plan switcher adjusted available minutes.',
            createdBy: 'billing-test-plan-switcher',
        );
    }
}
