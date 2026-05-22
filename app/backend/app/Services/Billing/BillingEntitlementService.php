<?php

namespace App\Services\Billing;

use App\Exceptions\BillingEntitlementException;
use App\Models\SubtitleJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class BillingEntitlementService
{
    private const ACTIVE_STATUSES = ['active', 'trialing'];

    public function __construct(
        private readonly BillingPlanCatalog $plans,
        private readonly UsageLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorizeForGeneration(User $user, array $payload, ?int $excludeJobId = null): GenerationEntitlement
    {
        $lockedUser = User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->firstOrFail();
        $plan = $this->activePlan($lockedUser);

        if ($plan === null) {
            throw BillingEntitlementException::paymentRequired();
        }

        if (($payload['enrichmentMode'] ?? null) === 'full' && ! $this->plans->hasFeature($plan, 'full_word_cards')) {
            throw BillingEntitlementException::featureUnavailable();
        }

        $period = $this->ledger->periodForUser($lockedUser);

        if ($period === null) {
            throw BillingEntitlementException::paymentRequired();
        }

        $this->ledger->ensureMonthlyGrant($lockedUser, $plan, $period['start'], $period['end']);

        $runningJobs = SubtitleJob::query()
            ->whereBelongsTo($lockedUser)
            ->where('status', 'running')
            ->when($excludeJobId !== null, fn ($query) => $query->whereKeyNot($excludeJobId))
            ->count();

        if ($runningJobs >= $this->plans->concurrency($plan)) {
            throw BillingEntitlementException::concurrencyExceeded();
        }

        $reservationMinutes = $this->ledger->billableMinutes($payload['videoDurationSeconds'] ?? null);

        if ($this->ledger->availableMinutes($lockedUser, $period['start'], $period['end']) < $reservationMinutes) {
            throw BillingEntitlementException::usageExhausted();
        }

        return new GenerationEntitlement(
            planCode: (string) $plan['code'],
            generationTier: $this->plans->generationTier($plan),
            reservationMinutes: $reservationMinutes,
        );
    }

    public function reserveForJob(SubtitleJob $job, GenerationEntitlement $entitlement): void
    {
        $user = $job->user;

        if (! $user instanceof User) {
            return;
        }

        $plan = $this->plans->requirePlan($entitlement->planCode);
        $this->ledger->reserveForJob($job, $user, $plan, $entitlement->reservationMinutes);
    }

    public function releaseJobReservation(SubtitleJob $job, string $reason): void
    {
        $this->ledger->releaseReservation($job->loadMissing('user'), $reason);
    }

    public function syncJobReservationToActualDuration(SubtitleJob $job): void
    {
        DB::transaction(function () use ($job): void {
            $lockedUser = User::query()
                ->whereKey($job->user_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedUser instanceof User) {
                return;
            }

            $plan = $this->activePlan($lockedUser);

            if ($plan === null) {
                throw BillingEntitlementException::paymentRequired();
            }

            $period = $this->ledger->periodForUser($lockedUser);

            if ($period === null) {
                throw BillingEntitlementException::paymentRequired();
            }

            $actualMinutes = $this->ledger->billableMinutes($job->video_duration_seconds);
            $reservedMinutes = $this->ledger->reservedMinutesForJob($job);
            $additionalMinutes = max(0, $actualMinutes - $reservedMinutes);

            $this->ledger->ensureMonthlyGrant($lockedUser, $plan, $period['start'], $period['end']);

            if ($additionalMinutes > 0 && $this->ledger->availableMinutes($lockedUser, $period['start'], $period['end']) < $additionalMinutes) {
                throw BillingEntitlementException::usageExhausted();
            }

            $this->ledger->adjustReservationToActualDuration($job->refresh()->load('user'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function accountSummary(User $user): array
    {
        $plan = $this->activePlan($user);
        $period = $this->ledger->periodForUser($user);

        if ($plan === null || $period === null) {
            return [
                'status' => 'authenticated',
                'id' => (string) $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'emailVerified' => $user->hasVerifiedEmail(),
                'planName' => 'No active plan',
                'tierName' => 'Inactive',
                'tierSpeedLabel' => 'Generation paused',
                'monthlyMinuteLimit' => 0,
                'monthlyMinutesUsed' => 0,
                'monthlyMinutesPending' => 0,
                'monthlyMinutesRemaining' => 0,
                'resetAt' => now()->addMonthNoOverflow()->startOfMonth()->toJSON(),
                'upgradeAvailable' => true,
            ];
        }

        $this->ledger->ensureMonthlyGrant($user, $plan, $period['start'], $period['end']);
        $summary = $this->ledger->summary($user, $period['start'], $period['end']);
        $monthlyMinutes = $this->plans->monthlyMinutes($plan);

        return [
            'status' => 'authenticated',
            'id' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'emailVerified' => $user->hasVerifiedEmail(),
            'planName' => $this->plans->name($plan),
            'tierName' => $this->plans->name($plan),
            'tierSpeedLabel' => $this->plans->speedLabel($plan),
            'monthlyMinuteLimit' => $monthlyMinutes,
            'monthlyMinutesUsed' => $summary['used'],
            'monthlyMinutesPending' => $summary['reserved'],
            'monthlyMinutesRemaining' => min($monthlyMinutes, $summary['available']),
            'resetAt' => $period['end']->toJSON(),
            'upgradeAvailable' => (string) $plan['code'] !== 'pro',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activePlan(User $user): ?array
    {
        if (! in_array($user->billing_subscription_status, self::ACTIVE_STATUSES, true)) {
            return null;
        }

        if ($user->billing_current_period_end === null || $user->billing_current_period_end->lte(now())) {
            return null;
        }

        return $this->plans->plan($user->billing_plan_code);
    }
}
