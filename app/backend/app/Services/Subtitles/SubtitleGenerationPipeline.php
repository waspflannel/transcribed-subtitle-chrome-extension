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
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Languages\LanguageCatalog;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubtitleGenerationPipeline
{
    public const QUEUE = 'subtitle-ai';

    public static function connection(): string
    {
        return (string) config('subtitles.queue.connection', 'database');
    }

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly ElevenLabsScribeTranscriptionService $transcriptionService,
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitleJobArtifactStore $artifacts,
    ) {}

    public function processTranscription(int $subtitleJobId): void
    {
        $this->extendProcessingTimeLimit();

        $job = $this->claimPreparingJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        $audio = null;
        $stage = 'acquiring-audio';

        try {
            $this->logger->audioAcquisitionStarted($job);

            $audio = $this->audioSource->acquire(
                youtubeUrl: $job->youtube_url,
                requestDurationSeconds: $job->video_duration_seconds,
            );

            $job->update(['video_duration_seconds' => $audio->durationSeconds]);
            $this->logger->audioAcquisitionCompleted($job->refresh(), $audio);

            $stage = 'transcribing';
            $this->markJobRunning($job, 'transcribing', 45);
            $this->logger->transcriptionStarted($job);

            $transcript = $this->transcriptionService->transcribe(
                audio: $audio,
                sourceLanguage: $job->source_language,
            );

            $this->logger->transcriptionCompleted($job, $transcript, $audio);
            $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);

            $job = $job->refresh();
            $draftCues = $this->tracks->draftCues($transcript);

            $this->artifacts->putTranscript($job, $transcript);
            $this->artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $draftCues);

            $this->dispatchAnalysisBatch($job);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, $stage, $exception);

            throw $exception;
        } finally {
            if ($audio instanceof TemporaryAudioFile) {
                $audio->delete();
            }
        }
    }

    public function tokenizeBatch(int $subtitleJobId, int $batchIndex): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        try {
            $result = $this->translationAnalysis->tokenizeCueBatch(
                batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
                allCues: $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues,
                sourceLanguage: $this->effectiveSourceLanguage($job),
            );

            $this->artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::TOKENIZED_CUES, $batchIndex, $result);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'tokenizing', $exception);

            throw $exception;
        }
    }

    public function translateBatch(int $subtitleJobId, int $batchIndex): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        try {
            $result = $this->translationAnalysis->translateCueBatch(
                batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
                sourceLanguage: $this->effectiveSourceLanguage($job),
                targetLanguage: $job->target_language,
            );

            $this->artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::TRANSLATED_CUES, $batchIndex, $result);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'translating', $exception);

            throw $exception;
        }
    }

    public function continueAfterAnalysis(int $subtitleJobId): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        $tokenized = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TOKENIZED_CUES);
        $this->logger->tokenizationCompleted($job, $tokenized);

        $translated = null;

        if ($this->translationRequested($job)) {
            $translated = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TRANSLATED_CUES);
            $this->logger->translationCompleted($job, $translated);
        }

        if ($job->include_romanization && $this->shouldRomanizeTranscript($tokenized->cues)) {
            $this->dispatchRomanizationBatch($job);

            return;
        }

        $this->storeMergedCuesAndContinue($job, $tokenized, $translated);
    }

    public function romanizeBatch(int $subtitleJobId, int $batchIndex): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        try {
            $result = $this->translationAnalysis->romanizeCueBatch(
                batch: $this->artifacts->cueBatchResult($job, SubtitleJobArtifactStore::TOKENIZED_CUES, $batchIndex)->cues,
                sourceLanguage: $this->effectiveSourceLanguage($job),
            );

            $this->artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::ROMANIZED_CUES, $batchIndex, $result);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'romanizing', $exception);

            throw $exception;
        }
    }

    public function continueAfterRomanization(int $subtitleJobId): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        $romanized = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ROMANIZED_CUES);
        $this->logger->romanizationCompleted($job, $romanized);

        $translated = $this->translationRequested($job)
            ? $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TRANSLATED_CUES)
            : null;

        $this->storeMergedCuesAndContinue($job, $romanized, $translated);
    }

    public function enrichBatch(int $subtitleJobId, int $batchIndex): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

        try {
            $result = $this->translationAnalysis->enrichCueBatch(
                batch: $this->artifacts->cueBatch($job, SubtitleJobArtifactStore::MERGED_CUES, $batchIndex),
                sourceLanguage: $this->effectiveSourceLanguage($job),
                targetLanguage: $job->target_language,
                includeRomanization: $job->include_romanization,
            );

            $this->artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::ENRICHED_CUES, $batchIndex, $result);
        } catch (Throwable $exception) {
            $this->failJob($subtitleJobId, 'enriching', $exception);

            throw $exception;
        }
    }

    public function finalize(int $subtitleJobId, bool $useEnrichedCues): void
    {
        $job = $this->loadRunningJob($subtitleJobId);

        if ($job === null) {
            return;
        }

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
    }

    public function failJob(int $subtitleJobId, string $stage, Throwable $exception): void
    {
        $job = SubtitleJob::query()->find($subtitleJobId);

        if ($job === null || in_array($job->status, ['completed', 'failed'], true)) {
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
            $jobs[] = new TokenizeSubtitleCueBatch($job->id, $batchIndex);

            if ($this->translationRequested($job)) {
                $jobs[] = new TranslateSubtitleCueBatch($job->id, $batchIndex);
            }
        }

        $subtitleJobId = $job->id;

        $this->dispatchBatch(
            jobs: $jobs,
            name: 'subtitle analysis '.$job->public_id,
            failedStage: 'tokenizing',
            then: static function (Batch $batch) use ($subtitleJobId): void {
                ContinueSubtitleJobAfterAnalysis::dispatch($subtitleJobId)
                    ->onConnection(self::connection())
                    ->onQueue(self::QUEUE);
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
            $jobs[] = new RomanizeSubtitleCueBatch($job->id, $batchIndex);
        }

        $subtitleJobId = $job->id;

        $this->dispatchBatch(
            jobs: $jobs,
            name: 'subtitle romanization '.$job->public_id,
            failedStage: 'romanizing',
            then: static function (Batch $batch) use ($subtitleJobId): void {
                ContinueSubtitleJobAfterRomanization::dispatch($subtitleJobId)
                    ->onConnection(self::connection())
                    ->onQueue(self::QUEUE);
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
            $jobs[] = new EnrichSubtitleCueBatch($job->id, $batchIndex);
        }

        $subtitleJobId = $job->id;

        $this->dispatchBatch(
            jobs: $jobs,
            name: 'subtitle enrichment '.$job->public_id,
            failedStage: 'enriching',
            then: static function (Batch $batch) use ($subtitleJobId): void {
                FinalizeSubtitleJob::dispatch($subtitleJobId, true)
                    ->onConnection(self::connection())
                    ->onQueue(self::QUEUE);
            },
        );
    }

    /**
     * @param  array<int, object>  $jobs
     */
    private function dispatchBatch(array $jobs, string $name, string $failedStage, callable $then): void
    {
        $subtitleJobId = $jobs[0]->subtitleJobId;

        Bus::batch($jobs)
            ->name($name)
            ->onConnection(self::connection())
            ->onQueue(self::QUEUE)
            ->then($then)
            ->catch(static function (Batch $batch, Throwable $exception) use ($subtitleJobId, $failedStage): void {
                app(SubtitleGenerationPipeline::class)->failJob($subtitleJobId, $failedStage, $exception);
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

        FinalizeSubtitleJob::dispatch($job->id, false)
            ->onConnection(self::connection())
            ->onQueue(self::QUEUE);
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

    private function loadRunningJob(int $subtitleJobId): ?SubtitleJob
    {
        $job = SubtitleJob::query()
            ->with('track')
            ->find($subtitleJobId);

        if ($job === null || $job->status !== 'running' || $this->hasReadyTrack($job)) {
            return null;
        }

        return $job;
    }

    private function claimPreparingJob(int $subtitleJobId): ?SubtitleJob
    {
        $updated = SubtitleJob::query()
            ->whereKey($subtitleJobId)
            ->where('status', 'running')
            ->where('stage', 'preparing')
            ->update([
                'stage' => 'acquiring-audio',
                'progress_percent' => 20,
                'error_code' => null,
                'error_message' => null,
            ]);

        if ($updated !== 1) {
            return null;
        }

        return $this->loadRunningJob($subtitleJobId);
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

    private function failIncompleteState(string $reason): never
    {
        throw SubtitleProcessingException::enrichmentFailed(
            'Subtitle processing state is incomplete.',
            ['reason' => $reason],
        );
    }

    private function extendProcessingTimeLimit(): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit((int) config('subtitles.processing_timeout_seconds', 0));
    }
}
