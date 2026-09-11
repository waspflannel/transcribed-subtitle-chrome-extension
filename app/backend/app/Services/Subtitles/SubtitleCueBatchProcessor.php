<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Text\SubtitleText;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubtitleCueBatchProcessor
{
    public function __construct(
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitleJobFailureHandler $failureHandler,
        private readonly SubtitlePipelineTelemetry $telemetry,
        private readonly SubtitleProviderCostRecorder $costs,
    ) {}

    public function analyzeCueBatch(int $subtitleJobId, int $batchIndex, string $runId, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null || $this->artifacts->hasArtifact($job, SubtitleJobArtifactStore::ANALYZED_CUES, $batchIndex)) {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'tokenizing', $batchIndex, $queuedAtMs);
        $this->telemetry->recordStageStarted($job, 'tokenizing', $batchIndex);

        try {
            $startedAtMs = $this->telemetry->currentTimeMs();
            $includeTranslation = $job->include_translation && $job->effectiveSourceLanguage() !== $job->target_language;
            $allCues = $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues;
            $includeRomanization = $job->include_romanization && SubtitleText::hasNonLatinCues($allCues);
            $batch = $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex);
            $result = $this->translationAnalysis->analyzeCueBatch(
                batch: $batch,
                allCues: $allCues,
                sourceLanguage: $job->effectiveSourceLanguage(),
                targetLanguage: $job->target_language,
                includeTranslation: $includeTranslation,
                includeRomanization: $includeRomanization,
                beforeRetry: fn (): bool => $this->loadRunningJob($subtitleJobId, $runId) !== null,
            );

            $job = $this->loadRunningJob($subtitleJobId, $runId);

            if ($job === null) {
                return;
            }

            DB::transaction(function () use ($job, $batchIndex, $result, $includeTranslation, $includeRomanization, $startedAtMs): void {
                $job = SubtitleJobLock::current($job->id, $job->run_id);
                if ($job === null || $job->status !== 'running' || $job->hasReadyTrack()) {
                    return;
                }
                $this->artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, $batchIndex, $result);
                $this->costs->recordAnalyzedCueBatch($job, count($result->cues), $includeTranslation, $includeRomanization);
                $this->telemetry->recordStageCompleted($job, 'tokenizing', $startedAtMs, $batchIndex);
            }, attempts: 5);
        } catch (Throwable $exception) {
            if ($this->loadRunningJob($subtitleJobId, $runId) === null) {
                return;
            }

            if (! ($exception instanceof SubtitleProcessingException && $exception->isTransient())) {
                $this->failureHandler->failJob($subtitleJobId, 'tokenizing', $exception, $runId, [
                    'batch_index' => $batchIndex,
                ]);
            }

            throw $exception;
        }
    }

    public function enrichCueBatch(int $subtitleJobId, int $batchIndex, string $runId, ?int $queuedAtMs = null): void
    {
        $this->runCueBatch(
            subtitleJobId: $subtitleJobId,
            batchIndex: $batchIndex,
            runId: $runId,
            queuedAtMs: $queuedAtMs,
            stage: 'enriching',
            artifactType: SubtitleJobArtifactStore::ENRICHED_CUES,
            process: function (SubtitleJob $job, int $batchIndex): CueEnrichmentResult {
                return $this->translationAnalysis->enrichCueBatch(
                    batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::MERGED_CUES, $batchIndex),
                    sourceLanguage: $job->effectiveSourceLanguage(),
                    targetLanguage: $job->target_language,
                );
            },
        );
    }

    /**
     * @param  callable(SubtitleJob, int): CueEnrichmentResult  $process
     */
    private function runCueBatch(
        int $subtitleJobId,
        int $batchIndex,
        string $runId,
        ?int $queuedAtMs,
        string $stage,
        string $artifactType,
        callable $process,
    ): void {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->telemetry->recordQueueWait($job, $stage, $batchIndex, $queuedAtMs);
        $this->telemetry->recordStageStarted($job, $stage, $batchIndex);

        try {
            $startedAtMs = $this->telemetry->currentTimeMs();
            $result = $process($job, $batchIndex);

            $job = $this->storeCueBatchResultIfJobStillRunning(
                subtitleJobId: $subtitleJobId,
                runId: $runId,
                artifactType: $artifactType,
                batchIndex: $batchIndex,
                result: $result,
            );

            if ($job === null) {
                return;
            }

            $this->costs->recordCueBatch($job, $stage, count($result->cues));
            $this->telemetry->recordStageCompleted($job, $stage, $startedAtMs, $batchIndex);
        } catch (Throwable $exception) {
            // Transient provider failures are retried by the queue job; failing
            // the subtitle job here would delete its artifacts and force the
            // whole pipeline to re-run from audio acquisition.
            if (! ($exception instanceof SubtitleProcessingException && $exception->isTransient())) {
                $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId, [
                    'batch_index' => $batchIndex,
                ]);
            }

            throw $exception;
        }
    }

    private function loadRunningJob(int $subtitleJobId, string $runId): ?SubtitleJob
    {
        $job = SubtitleJob::query()
            ->with('track')
            ->find($subtitleJobId);

        if ($job === null) {
            return null;
        }

        if ($job->run_id !== $runId) {
            $this->telemetry->recordStaleRunSkipped($job, $runId, (string) ($job->stage ?? 'unknown'));

            return null;
        }

        if ($job->status !== 'running' || $job->hasReadyTrack()) {
            return null;
        }

        return $job;
    }

    private function storeCueBatchResultIfJobStillRunning(
        int $subtitleJobId,
        string $runId,
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
}
