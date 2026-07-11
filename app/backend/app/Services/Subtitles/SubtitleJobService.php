<?php

namespace App\Services\Subtitles;

use App\Jobs\AcquireSubtitleAudio;
use App\Models\SubtitleJob;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Models\User;
use App\Services\Analytics\FunnelAnalytics;
use App\Services\Billing\BillingEntitlementService;
use App\Support\PostgresErrors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubtitleJobService
{
    private const VERSION_PREFIX = 'scribe-v2-tokenizer-v8-async-';

    private const PROCESSING_MODES = ['on_demand', 'full'];

    private const PROCESSING_FEATURE_SETS = [
        [false, false],
        [true, false],
        [false, true],
        [true, true],
    ];

    private const DISPATCH_STATE_REUSED = 'reused';

    private const DISPATCH_STATE_CREATED = 'created';

    private const DISPATCH_STATE_RESET = 'reset';

    public function __construct(
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitleRuntimeTracer $tracer,
        private readonly BillingEntitlementService $billing,
        private readonly FunnelAnalytics $analytics,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function currentProcessingVersions(): array
    {
        $versions = [];

        foreach (self::PROCESSING_MODES as $mode) {
            foreach (self::PROCESSING_FEATURE_SETS as [$includeRomanization, $includeTranslation]) {
                $versions[] = self::processingVersionFor($mode, $includeRomanization, $includeTranslation);
            }
        }

        return $versions;
    }

    public static function processingVersionFor(
        string $enrichmentMode,
        bool $includeRomanization,
        bool $includeTranslation,
    ): string {
        $mode = match ($enrichmentMode) {
            'on_demand' => 'on-demand',
            'full' => 'full',
            default => throw new InvalidArgumentException('Unsupported subtitle enrichment mode.'),
        };

        return self::VERSION_PREFIX
            .$mode
            .($includeRomanization ? '-romanized' : '')
            .($includeTranslation ? '-translated' : '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(
        array $payload,
        User $user,
        string $installId,
        ?string $requestIp,
        ?string $generationTier = null,
    ): SubtitleJob {
        $enrichmentMode = $payload['enrichmentMode'];
        $includeRomanization = $payload['includeRomanization'];
        $includeTranslation = $payload['includeTranslation'];
        $processingVersion = $this->processingVersion($enrichmentMode, $includeRomanization, $includeTranslation);
        $dispatchState = self::DISPATCH_STATE_REUSED;
        $previousJobCount = null;

        try {
            $job = DB::transaction(function () use (
                $payload,
                $user,
                $installId,
                $requestIp,
                $processingVersion,
                $enrichmentMode,
                $includeRomanization,
                $includeTranslation,
                &$dispatchState,
                &$previousJobCount,
            ): SubtitleJob {
                $job = $this->compatibleJobQuery($payload, $user, $processingVersion)
                    ->with('track')
                    ->lockForUpdate()
                    ->first();

                if ($job) {
                    if ($job->hasReadyTrack()) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    if ($job->status === 'running' && ! $this->isStalePreparingJob($job)) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    $this->billing->releaseJobReservation($job, 'reset');
                    $entitlement = $this->billing->authorizeForGeneration($user, $payload, $job->id);
                    $this->logger->incompleteJobReused($job);
                    $this->resetJob(
                        job: $job,
                        payload: $payload,
                        user: $user,
                        installId: $installId,
                        requestIp: $requestIp,
                        generationTier: $entitlement->generationTier,
                        enrichmentMode: $enrichmentMode,
                        includeRomanization: $includeRomanization,
                        includeTranslation: $includeTranslation,
                    );
                    $this->billing->reserveForJob($job->refresh()->load('user'), $entitlement);
                    $dispatchState = self::DISPATCH_STATE_RESET;

                    return $job->refresh();
                }

                $entitlement = $this->billing->authorizeForGeneration($user, $payload);
                $previousJobCount = SubtitleJob::query()
                    ->whereBelongsTo($user)
                    ->count();
                $job = $this->createJob(
                    payload: $payload,
                    user: $user,
                    installId: $installId,
                    requestIp: $requestIp,
                    processingVersion: $processingVersion,
                    generationTier: $entitlement->generationTier,
                    enrichmentMode: $enrichmentMode,
                    includeRomanization: $includeRomanization,
                    includeTranslation: $includeTranslation,
                );
                $this->billing->reserveForJob($job->load('user'), $entitlement);
                $this->logger->jobCreated($job);
                $dispatchState = self::DISPATCH_STATE_CREATED;

                return $job;
            });
        } catch (QueryException $exception) {
            if (! PostgresErrors::isUniqueViolation($exception)) {
                throw $exception;
            }

            $job = $this->compatibleJobQuery($payload, $user, $processingVersion)
                ->with('track')
                ->first();

            if ($job === null) {
                throw $exception;
            }

            $dispatchState = self::DISPATCH_STATE_REUSED;
        }

        $job = $job->refresh()->load('track');

        if ($dispatchState === self::DISPATCH_STATE_CREATED && $previousJobCount !== null) {
            $this->analytics->generationStarted($job->load('user'), $previousJobCount);
        }

        if ($job->hasReadyTrack()) {
            $this->logger->trackReused($job);

            return $job;
        }

        if (in_array($dispatchState, [self::DISPATCH_STATE_CREATED, self::DISPATCH_STATE_RESET], true)) {
            AcquireSubtitleAudio::dispatch($job->id, $job->run_id)
                ->onConnection(SubtitleQueue::connection())
                ->onQueue(SubtitleQueue::generationNameForJob($job));

            $job = $job->refresh()->load('track');
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Builder<SubtitleJob>
     */
    private function compatibleJobQuery(array $payload, User $user, string $processingVersion): Builder
    {
        return SubtitleJob::query()
            ->whereBelongsTo($user)
            ->where('youtube_video_id', $payload['youtubeVideoId'])
            ->where('source_language', $payload['sourceLanguage'])
            ->where('target_language', $payload['targetLanguage'])
            ->where('processing_version', $processingVersion);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(
        array $payload,
        User $user,
        string $installId,
        ?string $requestIp,
        string $processingVersion,
        string $generationTier,
        string $enrichmentMode,
        bool $includeRomanization,
        bool $includeTranslation,
    ): SubtitleJob {
        $job = SubtitleJob::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'run_id' => (string) Str::uuid(),
            'youtube_video_id' => $payload['youtubeVideoId'],
            'youtube_url' => $payload['youtubeUrl'],
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'source_language' => $payload['sourceLanguage'],
            'detected_source_language' => null,
            'target_language' => $payload['targetLanguage'],
            'processing_version' => $processingVersion,
            'generation_tier' => $generationTier,
            'enrichment_mode' => $enrichmentMode,
            'include_romanization' => $includeRomanization,
            'include_translation' => $includeTranslation,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'estimated_provider_cost_microusd' => 0,
            'install_id' => $installId,
            'request_ip' => $requestIp,
        ]);

        $this->tracer->jobEvent($job, 'job.created', [
            'stage' => 'preparing',
            'status' => 'running',
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
            'generation_tier' => $job->generation_tier,
            'queue' => SubtitleQueue::generationNameForJob($job),
        ]);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resetJob(
        SubtitleJob $job,
        array $payload,
        User $user,
        string $installId,
        ?string $requestIp,
        string $generationTier,
        string $enrichmentMode,
        bool $includeRomanization,
        bool $includeTranslation,
    ): void {
        $job->track()->delete();
        $job->artifacts()->delete();
        $job->unsetRelation('track');
        // Stage jobs of the superseded run no-op on the run-id guard, so the
        // old run's audio workspace is reclaimed here.
        SubtitleAudioWorkspace::delete((string) $job->run_id);

        $job->forceFill([
            'youtube_url' => $payload['youtubeUrl'],
            'user_id' => $user->id,
            'run_id' => (string) Str::uuid(),
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'detected_source_language' => null,
            'generation_tier' => $generationTier,
            'enrichment_mode' => $enrichmentMode,
            'include_romanization' => $includeRomanization,
            'include_translation' => $includeTranslation,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'estimated_provider_cost_microusd' => 0,
            'error_code' => null,
            'error_message' => null,
            'install_id' => $installId,
            'request_ip' => $requestIp,
            'expires_at' => null,
            'created_at' => now(),
        ])->save();

        $this->tracer->jobEvent($job->refresh(), 'job.reset', [
            'stage' => 'preparing',
            'status' => 'running',
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
            'generation_tier' => $job->generation_tier,
            'queue' => SubtitleQueue::generationNameForJob($job),
        ]);
    }

    private function processingVersion(string $enrichmentMode, bool $includeRomanization, bool $includeTranslation): string
    {
        return self::processingVersionFor($enrichmentMode, $includeRomanization, $includeTranslation);
    }

    private function isStalePreparingJob(SubtitleJob $job): bool
    {
        if ($job->stage !== 'preparing') {
            return false;
        }

        $seconds = max(1, (int) config('subtitles.queue.stale_preparing_seconds', 60));

        return $job->updated_at->lte(now()->subSeconds($seconds));
    }

}
