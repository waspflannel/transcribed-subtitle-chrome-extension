<?php

namespace App\Services\Subtitles;

use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Analytics\FunnelAnalytics;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Text\SubtitleText;
use App\Support\PostgresErrors;
use App\Support\SubtitleProcessingVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SubtitleJobService
{
    private const VERSION_PREFIX = SubtitleProcessingVersion::JOB;

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
        private readonly SubtitleJobFailureHandler $failureHandler,
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitleJobAdmission $admission,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function currentProcessingVersions(): array
    {
        $versions = [];

        foreach (self::PROCESSING_FEATURE_SETS as [$includeRomanization, $includeTranslation]) {
            $versions[] = self::processingVersionFor($includeRomanization, $includeTranslation);
        }

        return $versions;
    }

    public static function processingVersionFor(
        bool $includeRomanization,
        bool $includeTranslation,
    ): string {
        return self::VERSION_PREFIX
            .'on-demand'
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
        ?string $generationTier = null,
    ): SubtitleJob {
        $hints = array_values(array_unique(array_map(
            fn (string $hint): string => SubtitleText::collapseWhitespace($hint),
            $payload['vocabularyHints'] ?? [],
        )));
        sort($hints, SORT_STRING);
        $mode = (string) config('subtitles.transcription.ingestion_mode', 'upload');
        if (! in_array($mode, ['upload', 'youtube_url'], true)) {
            throw new InvalidArgumentException('Unsupported transcription ingestion mode.');
        }
        $selection = SubtitleModel::configured($payload['aiProvider'] ?? null);
        $payload['aiProvider'] = $selection->provider;
        $payload['aiModel'] = $selection->model;
        $payload['vocabularyHints'] = $hints;
        $payload['transcriptionIngestionMode'] = $mode;
        $payload['transcriptionOptionsHash'] = SubtitleProcessingVersion::transcriptionOptionsHash($hints, $mode);
        $includeRomanization = $payload['includeRomanization'];
        $includeTranslation = $payload['includeTranslation'];
        $processingVersion = $this->processingVersion($includeRomanization, $includeTranslation);
        $dispatchState = self::DISPATCH_STATE_REUSED;
        $previousJobCount = null;

        try {
            $job = DB::transaction(function () use (
                $payload,
                $user,
                $installId,
                $processingVersion,
                $includeRomanization,
                $includeTranslation,
                &$dispatchState,
                &$previousJobCount,
            ): SubtitleJob {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $job = $this->compatibleJobQuery($payload, $user, $processingVersion)
                    ->with('track')
                    ->lockForUpdate()
                    ->first();

                if ($job) {
                    if ($job->hasReadyTrack()) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    if (in_array($job->status, ['running', 'queued'], true) && ! $this->isStalePreparingJob($job)) {
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
                        generationTier: $entitlement->generationTier,
                        includeRomanization: $includeRomanization,
                        includeTranslation: $includeTranslation,
                        startImmediately: $entitlement->startImmediately,
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
                    processingVersion: $processingVersion,
                    generationTier: $entitlement->generationTier,
                    includeRomanization: $includeRomanization,
                    includeTranslation: $includeTranslation,
                    startImmediately: $entitlement->startImmediately,
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

            $job = DB::transaction(function () use ($payload, $user, $processingVersion): ?SubtitleJob {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                return $this->compatibleJobQuery($payload, $user, $processingVersion)
                    ->with('track')
                    ->lockForUpdate()
                    ->first();
            }, attempts: 5);

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

        if ($job->status === 'queued'
            && in_array($dispatchState, [self::DISPATCH_STATE_CREATED, self::DISPATCH_STATE_RESET], true)) {
            $this->admission->promoteQueuedJobs($user->id);

            return $job->refresh()->load('track');
        }

        // Queued jobs are dispatched later by SubtitleJobAdmission when a
        // running slot frees up.
        if ($job->status === 'running'
            && in_array($dispatchState, [self::DISPATCH_STATE_CREATED, self::DISPATCH_STATE_RESET], true)) {
            try {
                AcquireSubtitleAudio::dispatch($job->id, $job->run_id)
                    ->onConnection(SubtitleQueue::connection())
                    ->onQueue(SubtitleQueue::generationNameForJob($job));
            } catch (Throwable $exception) {
                if (SubtitleQueue::connection() === 'sync') {
                    throw $exception;
                }

                $this->failureHandler->failJob(
                    subtitleJobId: $job->id,
                    stage: 'preparing',
                    exception: SubtitleProcessingException::queuePublicationFailed(
                        ['reason' => 'queue_publication_failed'],
                        $exception,
                    ),
                    runId: (string) $job->run_id,
                    context: ['reason' => 'queue_publication_failed'],
                    promoteQueued: false,
                );
            }

            $job = $job->refresh()->load('track');
        }

        return $job;
    }

    public function delete(SubtitleJob $job, bool $completedOnly = false): bool
    {
        return DB::transaction(function () use ($job, $completedOnly): bool {
            $current = SubtitleJobLock::current($job->id, userId: $job->user_id);

            if ($current === null || ($completedOnly && $current->status !== 'completed')) {
                return false;
            }

            if ($current->status !== 'completed') {
                $this->billing->releaseJobReservation($current, 'deleted');
            }

            $runId = $current->run_id;
            $current->delete();
            DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($runId));

            return true;
        }, attempts: 5);
    }

    public function cancel(SubtitleJob $job, User $user): SubtitleJob
    {
        $promoteQueued = false;

        $cancelled = DB::transaction(function () use ($job, $user, &$promoteQueued): SubtitleJob {
            $current = SubtitleJobLock::current($job->id, $job->run_id, $user->id);

            if ($current === null) {
                throw (new ModelNotFoundException)->setModel(SubtitleJob::class, [$job->id]);
            }

            if ($current->status === 'cancelled') {
                return $current->load('track');
            }

            if (! in_array($current->status, ['queued', 'running'], true)) {
                throw SubtitleProcessingException::generationNotCancellable([
                    'status' => $current->status,
                ]);
            }

            $promoteQueued = $current->status === 'running';
            $runId = (string) $current->run_id;
            $this->billing->releaseJobReservation($current, 'cancelled');
            $this->artifacts->deleteForJob($current);
            $current->forceFill([
                'status' => 'cancelled',
                'error_code' => 'generation_cancelled',
                'error_message' => 'Generation was cancelled. Reserved minutes were released.',
                'expires_at' => now()->addDays(30),
            ])->save();
            $this->tracer->jobEvent($current, 'job.cancelled', [
                'stage' => $current->stage,
                'status' => 'cancelled',
                'reason' => 'generation_cancelled',
            ]);
            DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($runId));

            return $current->refresh()->load('track');
        }, attempts: 5);

        if ($promoteQueued) {
            $this->admission->promoteQueuedJobs($user->id);
        }

        return $cancelled;
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
            ->where('transcription_options_hash', $payload['transcriptionOptionsHash'])
            ->where('ai_provider', $payload['aiProvider'])
            ->where('ai_model', $payload['aiModel'])
            ->where('processing_version', $processingVersion);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(
        array $payload,
        User $user,
        string $installId,
        string $processingVersion,
        string $generationTier,
        bool $includeRomanization,
        bool $includeTranslation,
        bool $startImmediately,
    ): SubtitleJob {
        $createdAt = $startImmediately ? now() : $this->nextQueuedSubmissionAt($user);

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
            'ai_provider' => $payload['aiProvider'],
            'ai_model' => $payload['aiModel'],
            'vocabulary_hints' => $payload['vocabularyHints'],
            'transcription_ingestion_mode' => $payload['transcriptionIngestionMode'],
            'transcription_options_hash' => $payload['transcriptionOptionsHash'],
            'generation_tier' => $generationTier,
            'include_romanization' => $includeRomanization,
            'include_translation' => $includeTranslation,
            'status' => $startImmediately ? 'running' : 'queued',
            'stage' => 'preparing',
            'progress_percent' => $startImmediately ? 5 : 0,
            'estimated_provider_cost_microusd' => 0,
            'install_id' => $installId,
        ]);
        $job->forceFill(['created_at' => $createdAt])->saveQuietly();

        $this->tracer->jobEvent($job, 'job.created', [
            'stage' => 'preparing',
            'status' => $job->status,
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
        string $generationTier,
        bool $includeRomanization,
        bool $includeTranslation,
        bool $startImmediately,
    ): void {
        $job->track()->delete();
        $job->artifacts()->delete();
        $job->unsetRelation('track');
        // Stage jobs of the superseded run no-op on the run-id guard, so the
        // old run's audio workspace is reclaimed here.
        $oldRunId = (string) $job->run_id;
        DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($oldRunId));
        $createdAt = $startImmediately ? now() : $this->nextQueuedSubmissionAt($user);

        $job->forceFill([
            'youtube_url' => $payload['youtubeUrl'],
            'user_id' => $user->id,
            'run_id' => (string) Str::uuid(),
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'detected_source_language' => null,
            'generation_tier' => $generationTier,
            'include_romanization' => $includeRomanization,
            'include_translation' => $includeTranslation,
            'status' => $startImmediately ? 'running' : 'queued',
            'stage' => 'preparing',
            'progress_percent' => $startImmediately ? 5 : 0,
            'estimated_provider_cost_microusd' => 0,
            'error_code' => null,
            'error_message' => null,
            'install_id' => $installId,
            'expires_at' => null,
            'created_at' => $createdAt,
        ])->save();

        $this->tracer->jobEvent($job->refresh(), 'job.reset', [
            'stage' => 'preparing',
            'status' => $job->status,
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
            'generation_tier' => $job->generation_tier,
            'queue' => SubtitleQueue::generationNameForJob($job),
        ]);
    }

    private function nextQueuedSubmissionAt(User $user): Carbon
    {
        $createdAt = now();
        $latestQueuedAt = SubtitleJob::query()
            ->whereBelongsTo($user)
            ->where('status', 'queued')
            ->max('created_at');

        if ($latestQueuedAt === null) {
            return $createdAt;
        }

        $latestQueuedAt = Carbon::parse($latestQueuedAt);

        // Keep new and reset rows ordered behind waiting submissions at database precision.
        return $latestQueuedAt->greaterThanOrEqualTo($createdAt->copy()->startOfSecond())
            ? $latestQueuedAt->addSecond()
            : $createdAt;
    }

    private function processingVersion(bool $includeRomanization, bool $includeTranslation): string
    {
        return self::processingVersionFor($includeRomanization, $includeTranslation);
    }

    private function isStalePreparingJob(SubtitleJob $job): bool
    {
        // Queued jobs also sit at stage "preparing", but they wait for a
        // running slot by design and are never stale.
        if ($job->status !== 'running' || $job->stage !== 'preparing') {
            return false;
        }

        $seconds = max(1, (int) config('subtitles.queue.stale_preparing_seconds', 60));

        return $job->updated_at->lte(now()->subSeconds($seconds));
    }
}
