<?php

namespace App\Services\Billing;

use App\Exceptions\BillingEntitlementException;
use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Support\PostgresErrors;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use RuntimeException;

final class UsageLedger
{
    public function __construct(
        private readonly BillingPlanCatalog $plans,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     */
    public function ensureMonthlyGrant(
        User $user,
        array $plan,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): void {
        $targetMinutes = $this->plans->monthlyMinutes($plan);
        $alreadyGranted = (int) BillingUsageEvent::query()
            ->whereBelongsTo($user)
            ->where('event_type', 'monthly_grant')
            ->where('billing_period_start', $periodStart)
            ->where('billing_period_end', $periodEnd)
            ->sum('available_minutes_delta');

        $delta = max(0, $targetMinutes - $alreadyGranted);

        if ($delta === 0) {
            return;
        }

        $this->recordEvent(
            user: $user,
            planCode: (string) $plan['code'],
            eventType: 'monthly_grant',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            minutes: $delta,
            availableMinutesDelta: $delta,
            idempotencyKey: 'monthly-grant:'.$user->id.':'.$periodStart->timestamp.':'.$periodEnd->timestamp.':'.$plan['code'].':'.$targetMinutes,
            note: 'Monthly plan minute grant.',
        );
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function reserveForJob(SubtitleJob $job, User $user, array $plan, int $minutes): void
    {
        $period = $this->periodForUser($user);

        if ($period === null) {
            throw BillingEntitlementException::paymentRequired();
        }

        if ($minutes <= 0) {
            throw new InvalidArgumentException('Reservation minutes must be greater than zero.');
        }

        $this->recordEvent(
            user: $user,
            planCode: (string) $plan['code'],
            eventType: 'reservation',
            periodStart: $period['start'],
            periodEnd: $period['end'],
            minutes: $minutes,
            reservedMinutesDelta: $minutes,
            idempotencyKey: 'reservation:'.$job->id.':'.$job->run_id.':initial',
            subtitleJob: $job,
            stripeSubscriptionId: $user->stripe_subscription_id,
            note: 'Reserved minutes before subtitle provider work.',
        );
    }

    public function adjustReservationToActualDuration(SubtitleJob $job): void
    {
        $user = $job->user;

        if (! $user instanceof User) {
            throw new RuntimeException('Subtitle job is missing its billing user.');
        }

        if (! is_int($job->video_duration_seconds)) {
            throw new RuntimeException('Subtitle job is missing its measured duration.');
        }

        $actualMinutes = $this->billableMinutes($job->video_duration_seconds);
        $reservedMinutes = $this->reservedMinutesForJob($job);
        $delta = $actualMinutes - $reservedMinutes;

        if ($delta === 0) {
            return;
        }

        $reservation = $this->reservationIdentityForJob($job);

        if ($reservation === null) {
            return;
        }

        if ($delta > 0) {
            $this->recordEvent(
                user: $user,
                planCode: $reservation['planCode'],
                eventType: 'reservation',
                periodStart: $reservation['periodStart'],
                periodEnd: $reservation['periodEnd'],
                minutes: $delta,
                reservedMinutesDelta: $delta,
                idempotencyKey: 'reservation:'.$job->id.':'.$job->run_id.':duration:'.$actualMinutes,
                subtitleJob: $job,
                stripeSubscriptionId: $reservation['stripeSubscriptionId'],
                note: 'Reserved additional minutes after audio duration was measured.',
            );

            return;
        }

        $this->releaseMinutes(
            job: $job,
            user: $user,
            planCode: $reservation['planCode'],
            periodStart: $reservation['periodStart'],
            periodEnd: $reservation['periodEnd'],
            minutes: abs($delta),
            idempotencyKey: 'refund:'.$job->id.':'.$job->run_id.':duration:'.$actualMinutes,
            stripeSubscriptionId: $reservation['stripeSubscriptionId'],
            note: 'Released excess reserved minutes after audio duration was measured.',
        );
    }

    public function debitCompletedJob(SubtitleJob $job, SubtitleTrack $track): void
    {
        $user = $job->user;

        if (! $user instanceof User) {
            return;
        }

        $reservedMinutes = $this->reservedMinutesForJob($job);

        if ($reservedMinutes <= 0) {
            return;
        }

        $reservation = $this->reservationIdentityForJob($job);

        if ($reservation === null) {
            return;
        }

        $this->recordEvent(
            user: $user,
            planCode: $reservation['planCode'],
            eventType: 'debit',
            periodStart: $reservation['periodStart'],
            periodEnd: $reservation['periodEnd'],
            minutes: $reservedMinutes,
            availableMinutesDelta: -$reservedMinutes,
            reservedMinutesDelta: -$reservedMinutes,
            usedMinutesDelta: $reservedMinutes,
            providerCostMicrousdDelta: (int) $job->estimated_provider_cost_microusd,
            idempotencyKey: 'debit:'.$job->id.':'.$job->run_id,
            subtitleJob: $job,
            subtitleTrack: $track,
            stripeSubscriptionId: $reservation['stripeSubscriptionId'],
            note: 'Debited reserved minutes after a completed subtitle track was produced.',
        );
    }

    public function releaseReservation(SubtitleJob $job, string $reason): void
    {
        $user = $job->user;

        if (! $user instanceof User) {
            return;
        }

        $reservedMinutes = $this->reservedMinutesForJob($job);

        if ($reservedMinutes <= 0) {
            return;
        }

        $reservation = $this->reservationIdentityForJob($job);

        if ($reservation === null) {
            return;
        }

        $this->releaseMinutes(
            job: $job,
            user: $user,
            planCode: $reservation['planCode'],
            periodStart: $reservation['periodStart'],
            periodEnd: $reservation['periodEnd'],
            minutes: $reservedMinutes,
            idempotencyKey: 'refund:'.$job->id.':'.$job->run_id.':'.$reason,
            stripeSubscriptionId: $reservation['stripeSubscriptionId'],
            note: 'Released reserved minutes because no completed track was produced.',
        );
    }

    public function adjust(User $user, int $minutesDelta, string $note, string $createdBy): BillingUsageEvent
    {
        $period = $this->periodForUser($user) ?? [
            'start' => now()->startOfMonth()->toImmutable(),
            'end' => now()->addMonthNoOverflow()->startOfMonth()->toImmutable(),
        ];
        $planCode = is_string($user->billing_plan_code) && $user->billing_plan_code !== ''
            ? $user->billing_plan_code
            : 'support';

        return $this->recordEvent(
            user: $user,
            planCode: $planCode,
            eventType: 'adjustment',
            periodStart: $period['start'],
            periodEnd: $period['end'],
            minutes: abs($minutesDelta),
            availableMinutesDelta: $minutesDelta,
            idempotencyKey: 'adjustment:'.$user->id.':'.now()->format('YmdHisv').':'.hash('sha256', $note.$minutesDelta.$createdBy),
            createdBy: $createdBy,
            note: $note,
        );
    }

    /**
     * @return array{available: int, reserved: int, used: int, granted: int}
     */
    public function summary(User $user, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $query = BillingUsageEvent::query()
            ->whereBelongsTo($user)
            ->where('billing_period_start', $periodStart)
            ->where('billing_period_end', $periodEnd);

        $availableDelta = (int) (clone $query)->sum('available_minutes_delta');
        $reserved = (int) (clone $query)->sum('reserved_minutes_delta');
        $used = (int) (clone $query)->sum('used_minutes_delta');
        $granted = (int) (clone $query)
            ->where('event_type', 'monthly_grant')
            ->sum('available_minutes_delta');

        return [
            'available' => max(0, $availableDelta - $reserved),
            'reserved' => max(0, $reserved),
            'used' => max(0, $used),
            'granted' => max(0, $granted),
        ];
    }

    public function availableMinutes(User $user, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
    {
        return $this->summary($user, $periodStart, $periodEnd)['available'];
    }

    public function reservedMinutesForJob(SubtitleJob $job): int
    {
        return max(0, (int) BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('idempotency_key', 'like', '%:'.$job->id.':'.$job->run_id.':%')
            ->sum('reserved_minutes_delta'));
    }

    /**
     * @return array{planCode: string, periodStart: CarbonInterface, periodEnd: CarbonInterface, stripeSubscriptionId: ?string}|null
     */
    public function reservationIdentityForJob(SubtitleJob $job): ?array
    {
        $reservation = BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('event_type', 'reservation')
            ->where('idempotency_key', 'like', 'reservation:'.$job->id.':'.$job->run_id.':%')
            ->oldest('id')
            ->first();

        if (
            ! $reservation instanceof BillingUsageEvent
            || $reservation->billing_period_start === null
            || $reservation->billing_period_end === null
        ) {
            return null;
        }

        return [
            'planCode' => $reservation->plan_code,
            'periodStart' => $reservation->billing_period_start,
            'periodEnd' => $reservation->billing_period_end,
            'stripeSubscriptionId' => $reservation->stripe_subscription_id,
        ];
    }

    public function billableMinutes(?int $durationSeconds): int
    {
        return is_int($durationSeconds) && $durationSeconds > 0
            ? max(1, (int) ceil($durationSeconds / 60))
            : 1;
    }

    /**
     * @return array{start: CarbonInterface, end: CarbonInterface}|null
     */
    public function periodForUser(User $user): ?array
    {
        if ($user->billing_current_period_start === null || $user->billing_current_period_end === null) {
            return null;
        }

        return [
            'start' => $user->billing_current_period_start,
            'end' => $user->billing_current_period_end,
        ];
    }

    private function releaseMinutes(
        SubtitleJob $job,
        User $user,
        string $planCode,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        int $minutes,
        string $idempotencyKey,
        ?string $stripeSubscriptionId,
        string $note,
    ): void {
        $this->recordEvent(
            user: $user,
            planCode: $planCode,
            eventType: 'refund',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            minutes: $minutes,
            reservedMinutesDelta: -$minutes,
            idempotencyKey: $idempotencyKey,
            subtitleJob: $job,
            stripeSubscriptionId: $stripeSubscriptionId,
            note: $note,
        );
    }

    private function recordEvent(
        User $user,
        string $planCode,
        string $eventType,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        int $minutes,
        string $idempotencyKey,
        int $availableMinutesDelta = 0,
        int $reservedMinutesDelta = 0,
        int $usedMinutesDelta = 0,
        int $providerCostMicrousdDelta = 0,
        ?SubtitleJob $subtitleJob = null,
        ?SubtitleTrack $subtitleTrack = null,
        ?string $stripeSubscriptionId = null,
        ?string $createdBy = null,
        ?string $note = null,
    ): BillingUsageEvent {
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Billing usage events require an idempotency key.');
        }

        try {
            return BillingUsageEvent::create([
                'user_id' => $user->id,
                'subtitle_job_id' => $subtitleJob?->id,
                'subtitle_track_id' => $subtitleTrack?->id,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'plan_code' => $planCode,
                'event_type' => $eventType,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'minutes' => $minutes,
                'available_minutes_delta' => $availableMinutesDelta,
                'reserved_minutes_delta' => $reservedMinutesDelta,
                'used_minutes_delta' => $usedMinutesDelta,
                'provider_cost_microusd_delta' => $providerCostMicrousdDelta,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $createdBy,
                'note' => $note,
            ]);
        } catch (QueryException $exception) {
            if (! PostgresErrors::isUniqueViolation($exception)) {
                throw $exception;
            }

            return BillingUsageEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }
}
