<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\ContinueSubtitleJobAfterAnalysis;
use App\Jobs\ContinueSubtitleJobAfterRomanization;
use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\FinalizeSubtitleJob;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\TranslateSubtitleCueBatch;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Languages\LanguageCatalog;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

class SubtitleGenerationPipeline
{
    public const QUEUE = 'subtitle-ai';

    public static function connection(): string
    {
        return (string) config('subtitles.queue.connection', 'database');
    }

    public static function queue(): string
    {
        return (string) config('subtitles.queue.name', self::QUEUE);
    }

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly ElevenLabsScribeTranscriptionService $transcriptionService,
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitleRuntimeTracer $tracer,
    ) {}

    public function processTranscription(int $subtitleJobId, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $this->extendProcessingTimeLimit();

        $job = $this->claimPreparingJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'transcribing', null, $queuedAtMs);
        $this->recordStageStarted($job, 'acquiring-audio');

        $audio = null;
        $stage = 'acquiring-audio';

        try {
            $this->logger->audioAcquisitionStarted($job);

            $audioStartedAtMs = $this->currentTimeMs();
            $audio = $this->audioSource->acquire(
                youtubeUrl: $job->youtube_url,
                requestDurationSeconds: $job->video_duration_seconds,
            );

            $job->update(['video_duration_seconds' => $audio->durationSeconds]);
            $this->logger->audioAcquisitionCompleted($job->refresh(), $audio);
            $this->recordStageTiming($job->refresh(), 'acquiring-audio', $this->durationMs($audioStartedAtMs));

            $stage = 'transcribing';
            $this->markJobRunning($job, 'transcribing', 45);
            $this->logger->transcriptionStarted($job);
            $this->recordStageStarted($job->refresh(), 'transcribing');

            $transcriptionStartedAtMs = $this->currentTimeMs();
            $transcript = $this->transcriptionService->transcribe(
                audio: $audio,
                sourceLanguage: $job->source_language,
            );
            $this->recordStageTiming($job, 'transcribing', $this->durationMs($transcriptionStartedAtMs));

            $this->logger->transcriptionCompleted($job, $transcript, $audio);
            $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);

            $job = $job->refresh();
            $draftCues = $this->tracks->draftCues($transcript);

            $this->artifacts->putTranscript($job, $transcript);
            $this->artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $draftCues);

            $this->dispatchAnalysisBatch($job);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, $stage, $exception, $runId);

            throw $exception;
        } finally {
            if ($audio instanceof TemporaryAudioFile) {
                $audio->delete();
            }
        }
    }

    public function tokenizeBatch(int $subtitleJobId, int $batchIndex, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'tokenizing', $batchIndex, $queuedAtMs);
        $this->recordStageStarted($job, 'tokenizing', $batchIndex);

        try {
            $startedAtMs = $this->currentTimeMs();
            $result = $this->translationAnalysis->tokenizeCueBatch(
                batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
                allCues: $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues,
                sourceLanguage: $this->effectiveSourceLanguage($job),
            );

            $job = $this->storeCueBatchResultIfJobStillRunning(
                subtitleJobId: $subtitleJobId,
                runId: $runId,
                artifactType: SubtitleJobArtifactStore::TOKENIZED_CUES,
                batchIndex: $batchIndex,
                result: $result,
            );

            if ($job === null) {
                return;
            }

            $this->recordStageTiming($job, 'tokenizing', $this->durationMs($startedAtMs), $batchIndex);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'tokenizing', $exception, $runId, [
                'batch_index' => $batchIndex,
            ]);

            throw $exception;
        }
    }

    public function translateBatch(int $subtitleJobId, int $batchIndex, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'translating', $batchIndex, $queuedAtMs);
        $this->recordStageStarted($job, 'translating', $batchIndex);

        try {
            $startedAtMs = $this->currentTimeMs();
            $draftCues = $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues;
            $result = $this->translationAnalysis->translateCueBatch(
                batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
                sourceLanguage: $this->effectiveSourceLanguage($job),
                targetLanguage: $job->target_language,
                allCues: $draftCues,
            );

            $job = $this->storeCueBatchResultIfJobStillRunning(
                subtitleJobId: $subtitleJobId,
                runId: $runId,
                artifactType: SubtitleJobArtifactStore::TRANSLATED_CUES,
                batchIndex: $batchIndex,
                result: $result,
            );

            if ($job === null) {
                return;
            }

            $this->recordStageTiming($job, 'translating', $this->durationMs($startedAtMs), $batchIndex);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'translating', $exception, $runId, [
                'batch_index' => $batchIndex,
            ]);

            throw $exception;
        }
    }

    public function continueAfterAnalysis(int $subtitleJobId, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'analysis-continuation', null, $queuedAtMs);
        $this->recordStageStarted($job, 'analysis-continuation');
        $startedAtMs = $this->currentTimeMs();

        $tokenized = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TOKENIZED_CUES);
        $this->logger->tokenizationCompleted($job, $tokenized);

        $translated = null;

        if ($this->translationRequested($job)) {
            $translated = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TRANSLATED_CUES);
            $this->logger->translationCompleted($job, $translated);
        }

        if ($job->include_romanization && $this->shouldRomanizeTranscript($tokenized->cues)) {
            $this->recordStageTiming($job, 'analysis-continuation', $this->durationMs($startedAtMs));
            $this->dispatchRomanizationBatch($job);

            return;
        }

        $this->storeMergedCuesAndContinue($job, $tokenized, $translated);
        $this->recordStageTiming($job, 'analysis-continuation', $this->durationMs($startedAtMs));
    }

    public function romanizeBatch(int $subtitleJobId, int $batchIndex, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'romanizing', $batchIndex, $queuedAtMs);
        $this->recordStageStarted($job, 'romanizing', $batchIndex);

        try {
            $startedAtMs = $this->currentTimeMs();
            $result = $this->translationAnalysis->romanizeCueBatch(
                batch: $this->artifacts->cueBatchResult($job, SubtitleJobArtifactStore::TOKENIZED_CUES, $batchIndex)->cues,
                sourceLanguage: $this->effectiveSourceLanguage($job),
            );

            $job = $this->storeCueBatchResultIfJobStillRunning(
                subtitleJobId: $subtitleJobId,
                runId: $runId,
                artifactType: SubtitleJobArtifactStore::ROMANIZED_CUES,
                batchIndex: $batchIndex,
                result: $result,
            );

            if ($job === null) {
                return;
            }

            $this->recordStageTiming($job, 'romanizing', $this->durationMs($startedAtMs), $batchIndex);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'romanizing', $exception, $runId, [
                'batch_index' => $batchIndex,
            ]);

            throw $exception;
        }
    }

    public function continueAfterRomanization(int $subtitleJobId, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'romanization-continuation', null, $queuedAtMs);
        $this->recordStageStarted($job, 'romanization-continuation');
        $startedAtMs = $this->currentTimeMs();

        $romanized = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ROMANIZED_CUES);
        $this->logger->romanizationCompleted($job, $romanized);

        $translated = $this->translationRequested($job)
            ? $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TRANSLATED_CUES)
            : null;

        $this->storeMergedCuesAndContinue($job, $romanized, $translated);
        $this->recordStageTiming($job, 'romanization-continuation', $this->durationMs($startedAtMs));
    }

    public function enrichBatch(int $subtitleJobId, int $batchIndex, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'enriching', $batchIndex, $queuedAtMs);
        $this->recordStageStarted($job, 'enriching', $batchIndex);

        try {
            $startedAtMs = $this->currentTimeMs();
            $result = $this->translationAnalysis->enrichCueBatch(
                batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::MERGED_CUES, $batchIndex),
                sourceLanguage: $this->effectiveSourceLanguage($job),
                targetLanguage: $job->target_language,
                includeRomanization: $job->include_romanization,
            );

            $job = $this->storeCueBatchResultIfJobStillRunning(
                subtitleJobId: $subtitleJobId,
                runId: $runId,
                artifactType: SubtitleJobArtifactStore::ENRICHED_CUES,
                batchIndex: $batchIndex,
                result: $result,
            );

            if ($job === null) {
                return;
            }

            $this->recordStageTiming($job, 'enriching', $this->durationMs($startedAtMs), $batchIndex);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'enriching', $exception, $runId, [
                'batch_index' => $batchIndex,
            ]);

            throw $exception;
        }
    }

    public function finalize(int $subtitleJobId, bool $useEnrichedCues, ?string $runId = null, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->logQueueWait($job, 'finalizing', null, $queuedAtMs);
        $this->recordStageStarted($job, 'finalizing');
        $startedAtMs = $this->currentTimeMs();

        $transcript = $this->artifacts->transcript($job);
        $enrichment = $useEnrichedCues
            ? $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ENRICHED_CUES)
            : $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::MERGED_CUES);

        if ($useEnrichedCues) {
            $this->logger->enrichmentCompleted($job, $enrichment);
        }

        $this->markJobRunning($job, 'finalizing', 95);

        $track = DB::transaction(function () use ($job, $transcript, $enrichment) {
            $job->track()->delete();
            $track = $this->tracks->generate($job->refresh(), $transcript, $enrichment);
            $job->update([
                'status' => 'completed',
                'stage' => 'finalizing',
                'progress_percent' => 100,
                'error_code' => null,
                'error_message' => null,
                'expires_at' => $track->expires_at,
            ]);
            $this->artifacts->deleteForJob($job);

            return $track;
        });

        $this->logger->trackGenerated(
            job: $job->refresh()->load('track'),
            track: $track,
            audioDurationSeconds: (int) ($job->video_duration_seconds ?? $transcript->durationSeconds ?? 0),
        );
        $this->recordStageTiming($job, 'finalizing', $this->durationMs($startedAtMs));
        $this->logger->completedTrackTiming($job->refresh(), (int) abs(now()->diffInMilliseconds($job->created_at)));
        $this->tracer->jobEvent($job->refresh(), 'job.completed', [
            'stage' => 'finalizing',
            'status' => 'completed',
            'duration_ms' => (int) abs(now()->diffInMilliseconds($job->created_at)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function failJob(int $subtitleJobId, string $stage, Throwable $exception, ?string $runId = null, array $context = []): void
    {
        $job = SubtitleJob::query()->find($subtitleJobId);

        if ($job === null) {
            return;
        }

        if (! $this->runMatches($job, $runId)) {
            $this->traceStaleRunSkipped($job, $runId, $stage);

            return;
        }

        if (in_array($job->status, ['completed', 'failed'], true)) {
            return;
        }

        if ($exception instanceof SubtitleProcessingException) {
            $job->update([
                'status' => 'failed',
                'stage' => $stage,
                'error_code' => $exception->publicCode,
                'error_message' => $exception->getMessage(),
            ]);
            $this->artifacts->deleteForJob($job);
            $this->logger->processingFailed($job->refresh(), $stage, $exception);
            $this->traceFailure($job->refresh(), $stage, $exception, $context);

            return;
        }

        if ($this->isDatabaseLocked($exception)) {
            $queueException = SubtitleProcessingException::queueUnavailable(
                'Subtitle queue storage was busy while processing. Retry generation after the current job finishes.',
                ['reason' => 'database_locked'],
                $exception,
            );

            $job->update([
                'status' => 'failed',
                'stage' => $stage,
                'error_code' => $queueException->publicCode,
                'error_message' => $queueException->getMessage(),
            ]);
            $this->artifacts->deleteForJob($job);
            $this->logger->processingFailed($job->refresh(), $stage, $queueException);
            $this->traceFailure($job->refresh(), $stage, $queueException, $context);

            return;
        }

        $job->update([
            'status' => 'failed',
            'stage' => $stage,
            'error_code' => 'internal_error',
            'error_message' => 'Generation did not complete.',
        ]);
        $this->artifacts->deleteForJob($job);
        $this->logger->unexpectedFailure($job->refresh(), $stage, $exception);
        $this->traceFailure($job->refresh(), $stage, $exception, $context);
    }

    private function dispatchAnalysisBatch(SubtitleJob $job): void
    {
        $this->markJobRunning($job, 'tokenizing', 65);
        $cueCount = $this->artifacts->cueCount($job, SubtitleJobArtifactStore::DRAFT_CUES);
        $this->logger->tokenizationStarted($job, $cueCount);

        if ($this->translationRequested($job)) {
            $this->logger->translationStarted($job, $cueCount);
        }

        $jobs = [];
        $batchCount = $this->artifacts->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES);

        for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {
            $jobs[] = new TokenizeSubtitleCueBatch($job->id, $batchIndex, $job->run_id);

            if ($this->translationRequested($job)) {
                $jobs[] = new TranslateSubtitleCueBatch($job->id, $batchIndex, $job->run_id);
            }
        }

        $subtitleJobId = $job->id;
        $runId = $job->run_id;

        $this->dispatchBatch(
            jobs: $jobs,
            name: 'subtitle analysis '.$job->public_id,
            failedStage: 'tokenizing',
            runId: $runId,
            then: static function (Batch $batch) use ($subtitleJobId, $runId): void {
                ContinueSubtitleJobAfterAnalysis::dispatch($subtitleJobId, $runId)
                    ->onConnection(self::connection())
                    ->onQueue(self::queue());
            },
        );
    }

    private function dispatchRomanizationBatch(SubtitleJob $job): void
    {
        $this->markJobRunning($job, 'romanizing', 78);
        $cueCount = $this->artifacts->cueCount($job, SubtitleJobArtifactStore::DRAFT_CUES);
        $this->logger->romanizationStarted($job, $cueCount);

        $jobs = [];
        $batchCount = $this->artifacts->batchArtifactCount($job, SubtitleJobArtifactStore::TOKENIZED_CUES);

        for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {
            $jobs[] = new RomanizeSubtitleCueBatch($job->id, $batchIndex, $job->run_id);
        }

        $subtitleJobId = $job->id;
        $runId = $job->run_id;

        $this->dispatchBatch(
            jobs: $jobs,
            name: 'subtitle romanization '.$job->public_id,
            failedStage: 'romanizing',
            runId: $runId,
            then: static function (Batch $batch) use ($subtitleJobId, $runId): void {
                ContinueSubtitleJobAfterRomanization::dispatch($subtitleJobId, $runId)
                    ->onConnection(self::connection())
                    ->onQueue(self::queue());
            },
        );
    }

    private function dispatchEnrichmentBatch(SubtitleJob $job): void
    {
        $this->markJobRunning($job, 'enriching', 90);
        $cueCount = $this->artifacts->cueCount($job, SubtitleJobArtifactStore::MERGED_CUES);
        $this->logger->enrichmentStarted($job, $cueCount);

        $jobs = [];
        $batchCount = $this->artifacts->batchCount($job, SubtitleJobArtifactStore::MERGED_CUES);

        for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {
            $jobs[] = new EnrichSubtitleCueBatch($job->id, $batchIndex, $job->run_id);
        }

        $subtitleJobId = $job->id;
        $runId = $job->run_id;

        $this->dispatchBatch(
            jobs: $jobs,
            name: 'subtitle enrichment '.$job->public_id,
            failedStage: 'enriching',
            runId: $runId,
            then: static function (Batch $batch) use ($subtitleJobId, $runId): void {
                FinalizeSubtitleJob::dispatch($subtitleJobId, true, $runId)
                    ->onConnection(self::connection())
                    ->onQueue(self::queue());
            },
        );
    }

    /**
     * @param  array<int, object>  $jobs
     */
    private function dispatchBatch(array $jobs, string $name, string $failedStage, ?string $runId, callable $then): void
    {
        $subtitleJobId = $jobs[0]->subtitleJobId;
        $tracer = $this->tracer;

        Bus::batch($jobs)
            ->name($name)
            ->onConnection(self::connection())
            ->onQueue(self::queue())
            ->before(static function (Batch $batch) use ($subtitleJobId, $runId, $name, $tracer): void {
                $tracer->jobEventById($subtitleJobId, 'batch.dispatched', [
                    'run_id' => $runId,
                    'laravel_batch_id' => $batch->id,
                    'queue_connection' => self::connection(),
                    'queue' => self::queue(),
                    'batch_name' => $name,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                    'processed_jobs' => $batch->processedJobs(),
                ]);
            })
            ->progress(static function (Batch $batch) use ($subtitleJobId, $runId, $name, $tracer): void {
                $tracer->jobEventById($subtitleJobId, 'batch.progress', [
                    'run_id' => $runId,
                    'laravel_batch_id' => $batch->id,
                    'batch_name' => $name,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                    'processed_jobs' => $batch->processedJobs(),
                    'progress_percent' => $batch->progress(),
                ]);
            })
            ->then(static function (Batch $batch) use ($subtitleJobId, $runId, $name, $tracer, $then): void {
                $tracer->jobEventById($subtitleJobId, 'batch.completed', [
                    'run_id' => $runId,
                    'laravel_batch_id' => $batch->id,
                    'batch_name' => $name,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                    'processed_jobs' => $batch->processedJobs(),
                    'progress_percent' => $batch->progress(),
                ]);
                $then($batch);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($subtitleJobId, $failedStage, $runId, $name, $tracer): void {
                $tracer->jobEventById($subtitleJobId, 'batch.failed', [
                    'run_id' => $runId,
                    'stage' => $failedStage,
                    'laravel_batch_id' => $batch->id,
                    'batch_name' => $name,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                    'processed_jobs' => $batch->processedJobs(),
                    'exception' => $exception::class,
                ], 'error');
                app(SubtitleGenerationPipeline::class)->failJob($subtitleJobId, $failedStage, $exception, $runId, [
                    'laravel_batch_id' => $batch->id,
                ]);
            })
            ->finally(static function (Batch $batch) use ($subtitleJobId, $runId, $name, $tracer): void {
                $tracer->jobEventById($subtitleJobId, $batch->cancelled() ? 'batch.cancelled' : 'batch.finalized', [
                    'run_id' => $runId,
                    'laravel_batch_id' => $batch->id,
                    'batch_name' => $name,
                    'total_jobs' => $batch->totalJobs,
                    'pending_jobs' => $batch->pendingJobs,
                    'failed_jobs' => $batch->failedJobs,
                    'processed_jobs' => $batch->processedJobs(),
                    'progress_percent' => $batch->progress(),
                ], $batch->cancelled() ? 'warning' : 'info');
            })
            ->dispatch();
    }

    private function storeMergedCuesAndContinue(
        SubtitleJob $job,
        CueEnrichmentResult $base,
        ?CueEnrichmentResult $translated,
    ): void {
        $merged = $this->mergeTranslatedText($base, $translated);
        $this->artifacts->putCueCollection(
            job: $job,
            artifactType: SubtitleJobArtifactStore::MERGED_CUES,
            cues: $merged->cues,
            sourceDialect: $merged->sourceDialect,
        );

        if ($job->enrichment_mode === 'full' && ! $this->isSameLanguageGeneration($job)) {
            $this->dispatchEnrichmentBatch($job);

            return;
        }

        FinalizeSubtitleJob::dispatch($job->id, false, $job->run_id)
            ->onConnection(self::connection())
            ->onQueue(self::queue());
    }

    private function mergeTranslatedText(
        CueEnrichmentResult $base,
        ?CueEnrichmentResult $translated,
    ): CueEnrichmentResult {
        if ($translated === null) {
            return $base;
        }

        $translatedById = [];

        foreach ($translated->cues as $cue) {
            if (! is_string($cue['cueId'] ?? null) || ! is_string($cue['translatedText'] ?? null)) {
                $this->failIncompleteState('translated_cue_identity');
            }

            $translatedById[$cue['cueId']] = $cue['translatedText'];
        }

        $merged = array_map(function (array $cue) use ($translatedById): array {
            $cueId = $cue['cueId'] ?? null;

            if (! is_string($cueId) || ! array_key_exists($cueId, $translatedById)) {
                $this->failIncompleteState('missing_translated_cue');
            }

            return [
                ...$cue,
                'translatedText' => $translatedById[$cueId],
            ];
        }, $base->cues);

        return new CueEnrichmentResult($merged, $base->sourceDialect);
    }

    private function loadRunningJob(int $subtitleJobId, ?string $runId = null): ?SubtitleJob
    {
        $job = SubtitleJob::query()
            ->with('track')
            ->find($subtitleJobId);

        if ($job === null) {
            return null;
        }

        if (! $this->runMatches($job, $runId)) {
            $this->traceStaleRunSkipped($job, $runId, (string) ($job->stage ?? 'unknown'));

            return null;
        }

        if ($job->status !== 'running' || $this->hasReadyTrack($job)) {
            return null;
        }

        return $job;
    }

    private function storeCueBatchResultIfJobStillRunning(
        int $subtitleJobId,
        ?string $runId,
        string $artifactType,
        int $batchIndex,
        CueEnrichmentResult $result,
    ): ?SubtitleJob {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return null;
        }

        $this->artifacts->putCueBatchResult($job, $artifactType, $batchIndex, $result);

        return $job;
    }

    private function claimPreparingJob(int $subtitleJobId, ?string $runId): ?SubtitleJob
    {
        $query = SubtitleJob::query()
            ->whereKey($subtitleJobId)
            ->where('status', 'running')
            ->where('stage', 'preparing');

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        $updated = $query->update([
            'stage' => 'acquiring-audio',
            'progress_percent' => 20,
            'error_code' => null,
            'error_message' => null,
        ]);

        if ($updated !== 1) {
            $job = SubtitleJob::query()->find($subtitleJobId);

            if ($job !== null && ! $this->runMatches($job, $runId)) {
                $this->traceStaleRunSkipped($job, $runId, 'acquiring-audio');
            }

            return null;
        }

        return $this->loadRunningJob($subtitleJobId, $runId);
    }

    private function translationRequested(SubtitleJob $job): bool
    {
        return $job->include_translation && ! $this->isSameLanguageGeneration($job);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    private function shouldRomanizeTranscript(array $cues): bool
    {
        foreach ($cues as $cue) {
            if (is_string($cue['sourceText'] ?? null) && $this->containsNonLatinLetter($cue['sourceText'])) {
                return true;
            }
        }

        return false;
    }

    private function containsNonLatinLetter(string $text): bool
    {
        return preg_match('/(?!\p{Latin})\p{L}/u', $text) === 1;
    }

    private function recordDetectedSourceLanguage(
        SubtitleJob $job,
        string $requestedSourceLanguage,
        string $transcriptLanguage,
    ): void {
        if ($requestedSourceLanguage !== 'auto') {
            return;
        }

        $detectedSourceLanguage = LanguageCatalog::normalizeCode($transcriptLanguage);

        if ($detectedSourceLanguage === null) {
            throw SubtitleProcessingException::transcriptionFailed(
                'Transcription provider did not return a supported detected language.',
                [
                    'reason' => 'unsupported_detected_source_language',
                    'detected_source_language' => $transcriptLanguage,
                ],
            );
        }

        $job->update(['detected_source_language' => $detectedSourceLanguage]);
    }

    private function effectiveSourceLanguage(SubtitleJob $job): string
    {
        return $job->detected_source_language ?: $job->source_language;
    }

    private function isSameLanguageGeneration(SubtitleJob $job): bool
    {
        return $this->effectiveSourceLanguage($job) === $job->target_language;
    }

    private function markJobRunning(SubtitleJob $job, string $stage, int $progressPercent): void
    {
        $job->update([
            'status' => 'running',
            'stage' => $stage,
            'progress_percent' => $progressPercent,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }

    private function isDatabaseLocked(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && str_contains($current->getMessage(), 'database is locked')) {
                return true;
            }
        }

        return false;
    }

    private function failIncompleteState(string $reason): never
    {
        throw SubtitleProcessingException::enrichmentFailed(
            'Subtitle processing state is incomplete.',
            ['reason' => $reason],
        );
    }

    private function logQueueWait(SubtitleJob $job, string $stage, ?int $batchIndex, ?int $queuedAtMs): void
    {
        if ($queuedAtMs === null) {
            return;
        }

        $waitMs = max(0, $this->currentTimeMs() - $queuedAtMs);

        $this->logger->queueWaitObserved(
            job: $job,
            stage: $stage,
            waitMs: $waitMs,
            batchIndex: $batchIndex,
        );
        $this->tracer->jobEvent($job, 'queue.wait_observed', $this->batchContext([
            'stage' => $stage,
            'queue_connection' => self::connection(),
            'queue' => self::queue(),
            'wait_ms' => $waitMs,
        ], $batchIndex));
        $this->recordSlowQueueWait($job, $stage, $waitMs, $batchIndex);
    }

    private function recordStageStarted(SubtitleJob $job, string $stage, ?int $batchIndex = null): void
    {
        $this->tracer->jobEvent($job, 'stage.started', $this->batchContext([
            'stage' => $stage,
            'status' => $job->status,
            'queue_connection' => self::connection(),
            'queue' => self::queue(),
            'worker_pid' => getmypid() ?: null,
        ], $batchIndex));
    }

    private function recordStageTiming(SubtitleJob $job, string $stage, int $durationMs, ?int $batchIndex = null): void
    {
        $this->logger->stageTiming($job, $stage, $durationMs, $batchIndex);
        $this->tracer->jobEvent($job, 'stage.completed', $this->batchContext([
            'stage' => $stage,
            'status' => $job->status,
            'duration_ms' => $durationMs,
            'worker_pid' => getmypid() ?: null,
        ], $batchIndex));
        $this->recordSlowStage($job, $stage, $durationMs, $batchIndex);
    }

    private function recordSlowQueueWait(SubtitleJob $job, string $stage, int $waitMs, ?int $batchIndex): void
    {
        $thresholdMs = max(0, (int) config('subtitles.tracing.slow_queue_wait_ms', 0));

        if ($thresholdMs === 0 || $waitMs <= $thresholdMs) {
            return;
        }

        $this->tracer->jobEvent($job, 'stage.slow', $this->batchContext([
            'stage' => $stage,
            'wait_ms' => $waitMs,
            'threshold_ms' => $thresholdMs,
            'slow_type' => 'queue_wait',
        ], $batchIndex), 'warning');
    }

    private function recordSlowStage(SubtitleJob $job, string $stage, int $durationMs, ?int $batchIndex): void
    {
        $thresholdMs = max(0, (int) config('subtitles.tracing.slow_stage_ms', 0));

        if ($thresholdMs === 0 || $durationMs <= $thresholdMs) {
            return;
        }

        $this->tracer->jobEvent($job, 'stage.slow', $this->batchContext([
            'stage' => $stage,
            'duration_ms' => $durationMs,
            'threshold_ms' => $thresholdMs,
            'slow_type' => 'stage_duration',
        ], $batchIndex), 'warning');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function batchContext(array $context, ?int $batchIndex): array
    {
        if ($batchIndex === null) {
            return $context;
        }

        return [
            ...$context,
            'batch_index' => $batchIndex,
        ];
    }

    private function runMatches(SubtitleJob $job, ?string $runId): bool
    {
        return $runId === null || $job->run_id === null || $job->run_id === $runId;
    }

    private function traceStaleRunSkipped(SubtitleJob $job, ?string $queuedRunId, string $stage): void
    {
        $this->tracer->jobEvent($job, 'job.stale_run_skipped', [
            'stage' => $stage,
            'status' => $job->status,
            'queued_run_id' => $queuedRunId,
            'current_run_id' => $job->run_id,
        ], 'warning');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function traceFailure(SubtitleJob $job, string $stage, Throwable $exception, array $context): void
    {
        $previous = $exception->getPrevious();
        $lastSuccessfulEvent = $this->lastSuccessfulEvent($job);

        $this->tracer->jobEvent($job, 'job.failed', [
            'stage' => $stage,
            'status' => 'failed',
            'error_code' => $job->error_code,
            'exception' => $exception::class,
            'previous_exception' => $previous !== null ? $previous::class : null,
            'worker_pid' => getmypid() ?: null,
            'last_successful_event' => $lastSuccessfulEvent?->event,
            'last_successful_stage' => $lastSuccessfulEvent?->stage,
            ...$context,
        ], 'error');
    }

    private function lastSuccessfulEvent(SubtitleJob $job): ?SubtitleJobEvent
    {
        return $job->events()
            ->whereNotIn('event', [
                'artifact.deleted',
                'batch.failed',
                'job.failed',
                'job.stale_run_skipped',
                'queue.failed',
                'stage.slow',
            ])
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function durationMs(int $startedAtMs): int
    {
        return max(0, $this->currentTimeMs() - $startedAtMs);
    }

    private function extendProcessingTimeLimit(): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit((int) config('subtitles.processing_timeout_seconds', 0));
    }
}
