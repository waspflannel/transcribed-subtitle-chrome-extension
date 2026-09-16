<?php

namespace App\Services\Subtitles;

use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Text\SubtitleText;
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
            $includeTranslation = $job->include_translation && $job->source_language !== $job->target_language;
            ['batch' => $batch, 'context' => $context] = $this->artifacts->cueBatchWithContext($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex);
            $includeRomanization = $job->include_romanization && SubtitleText::hasNonLatinCues($batch);
            $result = $this->translationAnalysis->analyzeCueBatch(
                batch: $batch,
                allCues: $context,
                sourceLanguage: $job->source_language,
                targetLanguage: $job->target_language,
                includeTranslation: $includeTranslation,
                includeRomanization: $includeRomanization,
                beforeRetry: fn (): bool => $this->loadRunningJob($subtitleJobId, $runId) !== null,
                selection: SubtitleModel::forJob($job),
                job: $job,
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
                if ($this->artifacts->hasArtifact($job, SubtitleJobArtifactStore::ANALYZED_CUES, $batchIndex)) {
                    return;
                }
                $this->artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, $batchIndex, $result);
                if ($batchIndex === 0) {
                    $this->telemetry->recordFirstAnnotatedCueAvailable($job, $result->cues[array_key_last($result->cues)]['endMs']);
                }
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
}
