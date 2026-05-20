<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Throwable;

class SubtitleCueBatchProcessor
{
    public function __construct(
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitleJobFailureHandler $failureHandler,
        private readonly SubtitlePipelineTelemetry $telemetry,
    ) {}

    public function tokenizeCueBatch(int $subtitleJobId, int $batchIndex, string $runId, ?int $queuedAtMs = null): void
    {
        $this->runCueBatch(
            subtitleJobId: $subtitleJobId,
            batchIndex: $batchIndex,
            runId: $runId,
            queuedAtMs: $queuedAtMs,
            stage: 'tokenizing',
            artifactType: SubtitleJobArtifactStore::TOKENIZED_CUES,
            process: function (SubtitleJob $job, int $batchIndex): CueEnrichmentResult {
                return $this->translationAnalysis->tokenizeCueBatch(
                    batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
                    allCues: $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues,
                    sourceLanguage: $this->effectiveSourceLanguage($job),
                );
            },
        );
    }

    public function translateCueBatch(int $subtitleJobId, int $batchIndex, string $runId, ?int $queuedAtMs = null): void
    {
        $this->runCueBatch(
            subtitleJobId: $subtitleJobId,
            batchIndex: $batchIndex,
            runId: $runId,
            queuedAtMs: $queuedAtMs,
            stage: 'translating',
            artifactType: SubtitleJobArtifactStore::TRANSLATED_CUES,
            process: function (SubtitleJob $job, int $batchIndex): CueEnrichmentResult {
                $draftCues = $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues;

                return $this->translationAnalysis->translateCueBatch(
                    batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
                    sourceLanguage: $this->effectiveSourceLanguage($job),
                    targetLanguage: $job->target_language,
                    allCues: $draftCues,
                );
            },
        );
    }

    public function romanizeCueBatch(int $subtitleJobId, int $batchIndex, string $runId, ?int $queuedAtMs = null): void
    {
        $this->runCueBatch(
            subtitleJobId: $subtitleJobId,
            batchIndex: $batchIndex,
            runId: $runId,
            queuedAtMs: $queuedAtMs,
            stage: 'romanizing',
            artifactType: SubtitleJobArtifactStore::ROMANIZED_CUES,
            process: function (SubtitleJob $job, int $batchIndex): CueEnrichmentResult {
                return $this->translationAnalysis->romanizeCueBatch(
                    batch: $this->artifacts->cueBatchResult($job, SubtitleJobArtifactStore::TOKENIZED_CUES, $batchIndex)->cues,
                    sourceLanguage: $this->effectiveSourceLanguage($job),
                );
            },
        );
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
                    sourceLanguage: $this->effectiveSourceLanguage($job),
                    targetLanguage: $job->target_language,
                    includeRomanization: $job->include_romanization,
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

            $this->telemetry->recordStageCompleted($job, $stage, $startedAtMs, $batchIndex);
        } catch (Throwable $exception) {
            $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId, [
                'batch_index' => $batchIndex,
            ]);

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

        if ($job->status !== 'running' || $this->hasReadyTrack($job)) {
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

    private function effectiveSourceLanguage(SubtitleJob $job): string
    {
        return $job->detected_source_language ?: $job->source_language;
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }
}
