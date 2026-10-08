<?php

namespace App\Services\Subtitles;

use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Models\SubtitleJob;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\InstanceSettings;
use App\Support\PostgresErrors;
use App\Support\SubtitleProcessingVersion;
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
        private readonly SubtitleJobFailureHandler $failureHandler,
        private readonly SubtitleJobArtifactStore $artifacts,
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
        string $installId,
    ): SubtitleJob {
        $mode = (string) config('subtitles.transcription.ingestion_mode', 'upload');
        if (! in_array($mode, ['upload', 'youtube_url'], true)) {
            throw new InvalidArgumentException('Unsupported transcription ingestion mode.');
        }
        $payload['youtubeUrl'] = 'https://www.youtube.com/watch?v='.$payload['youtubeVideoId'];
        $selection = SubtitleModel::configured($payload['aiProvider'] ?? null, $payload['aiModel'] ?? null, $payload['aiFastMode'] ?? false);
        $payload['aiProvider'] = $selection->provider;
        $payload['aiModel'] = $selection->model;
        $payload['aiFastMode'] = $selection->fastMode;
        $payload['transcriptionIngestionMode'] = $mode;
        $payload['transcriptionOptionsHash'] = SubtitleProcessingVersion::transcriptionOptionsHash($mode);
        $includeRomanization = $payload['includeRomanization'];
        $includeTranslation = $payload['includeTranslation'];
        $processingVersion = $this->processingVersion($includeRomanization, $includeTranslation);
        $payload['reuseKey'] = hash('sha256', json_encode([
            $payload['youtubeVideoId'], $payload['sourceLanguage'], $payload['targetLanguage'],
            $processingVersion, $payload['transcriptionOptionsHash'],
            $payload['aiProvider'], $payload['aiModel'],
            ...($selection->provider === 'codex' ? [$selection->fastMode] : []),
        ], JSON_THROW_ON_ERROR));
        $dispatchState = self::DISPATCH_STATE_REUSED;

        try {
            $job = DB::transaction(function () use (
                $payload,
                $installId,
                $processingVersion,
                $includeRomanization,
                $includeTranslation,
                &$dispatchState,
            ): SubtitleJob {
                $job = $this->compatibleJobQuery($payload, $processingVersion)
                    ->with('track')
                    ->lockForUpdate()
                    ->first();

                if ($job) {
                    if ($job->reuse_key === null) {
                        SubtitleJob::withoutTimestamps(fn () => $job->updateQuietly(['reuse_key' => $payload['reuseKey']]));
                    }
                    if ($job->hasReadyTrack() && ! ($payload['forceRegenerate'] ?? false)) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    if (in_array($job->status, ['running', 'queued'], true) && ! $this->isStalePreparingJob($job)) {
                        $dispatchState = self::DISPATCH_STATE_REUSED;

                        return $job;
                    }

                    if ($job->track?->lyricsCorrection()->whereIn('status', ['queued', 'running'])->exists()) {
                        throw SubtitleProcessingException::lyricsCorrectionInProgress();
                    }

                    app(InstanceSettings::class)->requireGenerationKeys($payload['aiProvider']);
                    $this->logger->incompleteJobReused($job);
                    $this->resetJob(
                        job: $job,
                        payload: $payload,
                        installId: $installId,
                        includeRomanization: $includeRomanization,
                        includeTranslation: $includeTranslation,
                    );
                    $dispatchState = self::DISPATCH_STATE_RESET;

                    return $job->refresh();
                }

                app(InstanceSettings::class)->requireGenerationKeys($payload['aiProvider']);
                $job = $this->createJob(
                    payload: $payload,
                    installId: $installId,
                    processingVersion: $processingVersion,
                    includeRomanization: $includeRomanization,
                    includeTranslation: $includeTranslation,
                );
                $this->logger->jobCreated($job);
                $dispatchState = self::DISPATCH_STATE_CREATED;

                return $job;
            });
        } catch (QueryException $exception) {
            if (! PostgresErrors::isUniqueViolation($exception)) {
                throw $exception;
            }

            $job = DB::transaction(function () use ($payload, $processingVersion): ?SubtitleJob {
                return $this->compatibleJobQuery($payload, $processingVersion)
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

        if ($job->hasReadyTrack()) {
            $this->logger->trackReused($job);

            return $job;
        }

        if ($job->status === 'running'
            && in_array($dispatchState, [self::DISPATCH_STATE_CREATED, self::DISPATCH_STATE_RESET], true)) {
            try {
                AcquireSubtitleAudio::dispatch($job->id, $job->run_id)
                    ->onConnection(SubtitleQueue::connection())
                    ->onQueue(SubtitleQueue::generationName());
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
                );
            }

            $job = $job->refresh()->load('track');
        }

        return $job;
    }

    public function delete(SubtitleJob $job, bool $completedOnly = false): bool
    {
        return DB::transaction(function () use ($job, $completedOnly): bool {
            $current = SubtitleJobLock::current($job->id);

            if ($current === null || ($completedOnly && $current->status !== 'completed')) {
                return false;
            }

            $runId = $current->run_id;
            $current->delete();
            DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($runId));

            return true;
        }, attempts: 5);
    }

    public function cancel(SubtitleJob $job): SubtitleJob
    {
        $cancelled = DB::transaction(function () use ($job): SubtitleJob {
            $current = SubtitleJobLock::current($job->id, $job->run_id);

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

            $runId = (string) $current->run_id;
            $this->artifacts->deleteForJob($current);
            $current->forceFill([
                'status' => 'cancelled',
                'error_code' => 'generation_cancelled',
                'error_message' => 'Generation was cancelled.',
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

        return $cancelled;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Builder<SubtitleJob>
     */
    private function compatibleJobQuery(array $payload, string $processingVersion): Builder
    {
        return SubtitleJob::query()
            ->withExists(['track as has_reusable_track' => fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))])
            ->where('youtube_video_id', $payload['youtubeVideoId'])
            ->where('source_language', $payload['sourceLanguage'])
            ->where('target_language', $payload['targetLanguage'])
            ->where('transcription_options_hash', $payload['transcriptionOptionsHash'])
            ->where('ai_provider', $payload['aiProvider'])
            ->where('ai_model', $payload['aiModel'])
            ->where('ai_fast_mode', $payload['aiFastMode'])
            ->where('processing_version', $processingVersion)
            ->orderByRaw('case when reuse_key is null then 1 else 0 end')
            ->orderByDesc('has_reusable_track')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(
        array $payload,
        string $installId,
        string $processingVersion,
        bool $includeRomanization,
        bool $includeTranslation,
    ): SubtitleJob {
        $createdAt = now();

        $job = SubtitleJob::create([
            'public_id' => (string) Str::uuid(),
            'reuse_key' => $payload['reuseKey'],
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
            'ai_fast_mode' => $payload['aiFastMode'],
            'transcription_ingestion_mode' => $payload['transcriptionIngestionMode'],
            'transcription_options_hash' => $payload['transcriptionOptionsHash'],
            'include_romanization' => $includeRomanization,
            'include_translation' => $includeTranslation,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'estimated_provider_cost_microusd' => 0,
            'install_id' => $installId,
        ]);
        $job->forceFill(['created_at' => $createdAt])->saveQuietly();

        $this->tracer->jobEvent($job, 'job.created', [
            'stage' => 'preparing',
            'status' => $job->status,
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
            'queue' => SubtitleQueue::generationName(),
            'provider' => $job->ai_provider,
            'model' => $job->ai_model,
            ...($job->ai_provider === 'codex' ? ['fast_mode' => (bool) $job->ai_fast_mode] : []),
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
        bool $includeRomanization,
        bool $includeTranslation,
    ): void {
        $job->track()->delete();
        $job->artifacts()->delete();
        $job->unsetRelation('track');
        // Stage jobs of the superseded run no-op on the run-id guard, so the
        // old run's audio workspace is reclaimed here.
        $oldRunId = (string) $job->run_id;
        DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($oldRunId));
        $createdAt = now();

        $job->forceFill([
            'youtube_url' => $payload['youtubeUrl'],
            'run_id' => (string) Str::uuid(),
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'ai_provider' => $payload['aiProvider'],
            'ai_model' => $payload['aiModel'],
            'ai_fast_mode' => $payload['aiFastMode'],
            'detected_source_language' => null,
            'include_romanization' => $includeRomanization,
            'include_translation' => $includeTranslation,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
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
            'queue' => SubtitleQueue::generationName(),
            'provider' => $job->ai_provider,
            'model' => $job->ai_model,
            ...($job->ai_provider === 'codex' ? ['fast_mode' => (bool) $job->ai_fast_mode] : []),
        ]);
    }

    private function processingVersion(bool $includeRomanization, bool $includeTranslation): string
    {
        return self::processingVersionFor($includeRomanization, $includeTranslation);
    }

    private function isStalePreparingJob(SubtitleJob $job): bool
    {
        // Legacy queued jobs are not running and cannot be stale workers.
        if ($job->status !== 'running' || $job->stage !== 'preparing') {
            return false;
        }

        $seconds = max(1, (int) config('subtitles.queue.stale_preparing_seconds', 60));

        return $job->updated_at->lte(now()->subSeconds($seconds));
    }
}
