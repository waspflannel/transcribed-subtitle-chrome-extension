<?php

namespace App\Services\Subtitles;

use App\Jobs\ProcessSubtitleJob;
use App\Models\SubtitleJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubtitleJobService
{
    public const PROCESSING_VERSION_ON_DEMAND = 'scribe-v2-tokenizer-v8-async-on-demand';

    public const PROCESSING_VERSION_ON_DEMAND_ROMANIZED = 'scribe-v2-tokenizer-v8-async-on-demand-romanized';

    public const PROCESSING_VERSION_ON_DEMAND_TRANSLATED = 'scribe-v2-tokenizer-v8-async-on-demand-translated';

    public const PROCESSING_VERSION_ON_DEMAND_ROMANIZED_TRANSLATED = 'scribe-v2-tokenizer-v8-async-on-demand-romanized-translated';

    public const PROCESSING_VERSION_FULL = 'scribe-v2-tokenizer-v8-async-full';

    public const PROCESSING_VERSION_FULL_ROMANIZED = 'scribe-v2-tokenizer-v8-async-full-romanized';

    public const PROCESSING_VERSION_FULL_TRANSLATED = 'scribe-v2-tokenizer-v8-async-full-translated';

    public const PROCESSING_VERSION_FULL_ROMANIZED_TRANSLATED = 'scribe-v2-tokenizer-v8-async-full-romanized-translated';

    public const CURRENT_PROCESSING_VERSIONS = [
        self::PROCESSING_VERSION_ON_DEMAND,
        self::PROCESSING_VERSION_ON_DEMAND_ROMANIZED,
        self::PROCESSING_VERSION_ON_DEMAND_TRANSLATED,
        self::PROCESSING_VERSION_ON_DEMAND_ROMANIZED_TRANSLATED,
        self::PROCESSING_VERSION_FULL,
        self::PROCESSING_VERSION_FULL_ROMANIZED,
        self::PROCESSING_VERSION_FULL_TRANSLATED,
        self::PROCESSING_VERSION_FULL_ROMANIZED_TRANSLATED,
    ];

    private const DISPATCH_STATE_REUSED = 'reused';

    private const DISPATCH_STATE_CREATED = 'created';

    private const DISPATCH_STATE_RESET = 'reset';

    public function __construct(
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitleRuntimeTracer $tracer,
        private readonly SubtitleQueueWorkerBootstrapper $workers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(
        array $payload,
        string $installId,
        ?string $requestIp,
        ?string $generationTier = null,
    ): SubtitleJob {
        $enrichmentMode = $payload['enrichmentMode'];
        $includeRomanization = $payload['includeRomanization'];
        $includeTranslation = $payload['includeTranslation'];
        $processingVersion = $this->processingVersion($enrichmentMode, $includeRomanization, $includeTranslation);
        $generationTier = SubtitleTier::normalize($generationTier ?? SubtitleTier::default());
        $dispatchState = self::DISPATCH_STATE_REUSED;

        try {
            $job = DB::transaction(function () use (
                $payload,
                $installId,
                $requestIp,
                $processingVersion,
                $generationTier,
                $enrichmentMode,
                $includeRomanization,
                $includeTranslation,
                &$dispatchState,
            ): SubtitleJob {
                $job = $this->compatibleJobQuery($payload, $installId, $processingVersion)
                    ->with('track')
                    ->lockForUpdate()
                    ->first();

                if ($job) {
                    if ($this->hasReadyTrack($job)) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    if ($job->status === 'running' && ! $this->isStalePreparingJob($job)) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    $this->logger->incompleteJobReused($job);
                    $this->resetJob(
                        job: $job,
                        payload: $payload,
                        installId: $installId,
                        requestIp: $requestIp,
                        generationTier: $generationTier,
                        enrichmentMode: $enrichmentMode,
                        includeRomanization: $includeRomanization,
                        includeTranslation: $includeTranslation,
                    );
                    $dispatchState = self::DISPATCH_STATE_RESET;

                    return $job->refresh();
                }

                $job = $this->createJob(
                    payload: $payload,
                    installId: $installId,
                    requestIp: $requestIp,
                    processingVersion: $processingVersion,
                    generationTier: $generationTier,
                    enrichmentMode: $enrichmentMode,
                    includeRomanization: $includeRomanization,
                    includeTranslation: $includeTranslation,
                );
                $this->logger->jobCreated($job);
                $dispatchState = self::DISPATCH_STATE_CREATED;

                return $job;
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $job = $this->compatibleJobQuery($payload, $installId, $processingVersion)
                ->with('track')
                ->first();

            if ($job === null) {
                throw $exception;
            }

            $dispatchState = self::DISPATCH_STATE_REUSED;
        }

        $job = $job->refresh()->load('track');

        if ($this->hasReadyTrack($job)) {
            $this->logger->trackReused($job);

            return $job;
        }

        if (in_array($dispatchState, [self::DISPATCH_STATE_CREATED, self::DISPATCH_STATE_RESET], true)) {
            ProcessSubtitleJob::dispatch($job->id, $job->run_id)
                ->onConnection(SubtitleQueue::connection())
                ->onQueue(SubtitleQueue::nameForJob($job));

            $job = $job->refresh()->load('track');
        }

        $this->workers->ensureRunning();

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Builder<SubtitleJob>
     */
    private function compatibleJobQuery(array $payload, string $installId, string $processingVersion): Builder
    {
        return SubtitleJob::query()
            ->where('youtube_video_id', $payload['youtubeVideoId'])
            ->where('source_language', $payload['sourceLanguage'])
            ->where('target_language', $payload['targetLanguage'])
            ->where('processing_version', $processingVersion)
            ->where('install_id', $installId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(
        array $payload,
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
            'queue' => SubtitleQueue::nameForJob($job),
        ]);

        return $job;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resetJob(
        SubtitleJob $job,
        array $payload,
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

        $job->forceFill([
            'youtube_url' => $payload['youtubeUrl'],
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
            'queue' => SubtitleQueue::nameForJob($job),
        ]);
    }

    private function processingVersion(string $enrichmentMode, bool $includeRomanization, bool $includeTranslation): string
    {
        if ($enrichmentMode === 'full') {
            if ($includeRomanization && $includeTranslation) {
                return self::PROCESSING_VERSION_FULL_ROMANIZED_TRANSLATED;
            }

            if ($includeRomanization) {
                return self::PROCESSING_VERSION_FULL_ROMANIZED;
            }

            return $includeTranslation
                ? self::PROCESSING_VERSION_FULL_TRANSLATED
                : self::PROCESSING_VERSION_FULL;
        }

        if ($includeRomanization && $includeTranslation) {
            return self::PROCESSING_VERSION_ON_DEMAND_ROMANIZED_TRANSLATED;
        }

        if ($includeRomanization) {
            return self::PROCESSING_VERSION_ON_DEMAND_ROMANIZED;
        }

        return $includeTranslation
            ? self::PROCESSING_VERSION_ON_DEMAND_TRANSLATED
            : self::PROCESSING_VERSION_ON_DEMAND;
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }

    private function isStalePreparingJob(SubtitleJob $job): bool
    {
        if ($job->stage !== 'preparing') {
            return false;
        }

        $seconds = max(1, (int) config('subtitles.queue.stale_preparing_seconds', 60));

        return $job->updated_at->lte(now()->subSeconds($seconds));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
