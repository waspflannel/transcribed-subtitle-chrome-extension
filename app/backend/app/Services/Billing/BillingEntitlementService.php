<?php

namespace App\Services\Billing;

use App\Exceptions\BillingEntitlementException;
use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Subtitles\SubtitleQueue;
use App\Services\Subtitles\SubtitleTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class BillingEntitlementService
{
    private const ACTIVE_STATUSES = ['active', 'trialing'];

    private const TERMINAL_SUBSCRIPTION_STATUSES = ['canceled', 'incomplete_expired'];

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
        $generationTier = SubtitleTier::normalize($this->plans->generationTier($plan));
        $generationLimit = SubtitleTier::generationConcurrency($generationTier);
        $submissionLimit = SubtitleTier::submissionLimit($generationTier);

        $activeJobCounts = SubtitleJob::query()
            ->whereBelongsTo($lockedUser)
            ->whereIn('status', ['running', 'queued'])
            ->when($excludeJobId !== null, fn ($query) => $query->whereKeyNot($excludeJobId))
            ->selectRaw("sum(case when status = 'running' then 1 else 0 end) as running_count")
            ->selectRaw('count(*) as active_count')
            ->first();
        $runningJobs = (int) ($activeJobCounts->running_count ?? 0);
        $activeJobs = (int) ($activeJobCounts->active_count ?? 0);

        if ($activeJobs >= $submissionLimit) {
            $this->logQueueFullRejected($lockedUser, $generationTier, $submissionLimit, $activeJobs);

            throw BillingEntitlementException::queueFull();
        }

        $reservationMinutes = $this->ledger->billableMinutes($payload['videoDurationSeconds'] ?? null);

        if ($this->ledger->availableMinutes($lockedUser, $period['start'], $period['end']) < $reservationMinutes) {
            throw BillingEntitlementException::usageExhausted();
        }

        return new GenerationEntitlement(
            planCode: (string) $plan['code'],
            generationTier: $generationTier,
            reservationMinutes: $reservationMinutes,
            startImmediately: $runningJobs < $generationLimit,
        );
    }

    public function reserveForJob(SubtitleJob $job, GenerationEntitlement $entitlement): void
    {
        $user = $job->user;

        if (! $user instanceof User) {
            throw new RuntimeException('Subtitle job is missing its billing user.');
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

            $reservation = $this->ledger->reservationIdentityForJob($job);

            if ($reservation === null) {
                return;
            }

            $actualMinutes = $this->ledger->billableMinutes($job->video_duration_seconds);
            $reservedMinutes = $this->ledger->reservedMinutesForJob($job);
            $additionalMinutes = max(0, $actualMinutes - $reservedMinutes);

            if ($additionalMinutes > 0 && $this->ledger->availableMinutes($lockedUser, $reservation['periodStart'], $reservation['periodEnd']) < $additionalMinutes) {
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

    public function subscriptionRequiresPortal(User $user): bool
    {
        if (! is_string($user->billing_subscription_status) || $user->billing_subscription_status === '') {
            return false;
        }

        return ! in_array($user->billing_subscription_status, self::TERMINAL_SUBSCRIPTION_STATUSES, true);
    }

    private function logQueueFullRejected(User $user, string $tier, int $submissionLimit, int $activeCount): void
    {
        Log::warning('backend.generation_queue_full_rejected', [
            'user_hash' => substr(hash('sha256', (string) $user->id), 0, 16),
            'queue_family' => SubtitleQueue::FAMILY_GENERATION,
            'limiter_type' => 'generation_admission',
            'generation_tier' => $tier,
            'submission_limit' => $submissionLimit,
            'observed_active_count' => $activeCount,
            'delay_reason' => 'limit_reached',
        ]);
    }
}
