<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\OptimizeSubtitleAudio;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Services\Audio\ScribeAudioChunker;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\UsageLedger;
use App\Services\Languages\LanguageCatalog;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\VideoTranscriptCache;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubtitleGenerationPipeline
{
    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly ElevenLabsScribeTranscriptionService $transcriptionService,
        private readonly ScribeAudioChunker $chunker,
        private readonly VideoTranscriptCache $transcriptCache,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
        private readonly SubtitleWorkflowLogger $logger,
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly SubtitlePipelineTelemetry $telemetry,
        private readonly SubtitleProviderCostRecorder $costs,
        private readonly SubtitleBatchDispatcher $batchDispatcher,
        private readonly SubtitleJobFailureHandler $failureHandler,
        private readonly BillingEntitlementService $billing,
        private readonly UsageLedger $usageLedger,
        private readonly SubtitleJobAdmission $admission,
    ) {}

    /**
     * Generation stage 1: claim the preparing job and download the source
     * audio, then hand off to OptimizeSubtitleAudio. A cached transcript
     * skips the audio stages entirely and dispatches analysis directly.
     */
    public function acquireAudioAndContinue(int $subtitleJobId, string $runId, ?int $queuedAtMs = null): void
    {
        $this->extendProcessingTimeLimit();

        $job = $this->claimPreparingJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'acquiring-audio', null, $queuedAtMs);

        $stage = 'acquiring-audio';

        try {
            // A cached transcript for this video makes acquire, optimize, and
            // transcribe unnecessary -- roughly 45% of a job's wall time.
            $cached = $this->transcriptCache->find($job->youtube_video_id, $job->source_language);

            if ($cached !== null) {
                $stage = 'transcribing';
                $this->continueWithCachedTranscript($job, $cached);

                return;
            }

            $this->telemetry->recordStageStarted($job, 'acquiring-audio');
            $this->logger->audioAcquisitionStarted($job);

            $audioStartedAtMs = $this->telemetry->currentTimeMs();
            $audio = $this->audioSource->acquire(
                youtubeUrl: $job->youtube_url,
                requestDurationSeconds: $job->video_duration_seconds,
                workDirectory: SubtitleAudioWorkspace::directory($runId),
            );

            $job->update(['video_duration_seconds' => $audio->durationSeconds]);
            $job = $job->refresh()->load('user');
            $this->billing->syncJobReservationToActualDuration($job);
            $this->logger->audioAcquisitionCompleted($job, $audio);
            $this->telemetry->recordStageCompleted($job, 'acquiring-audio', $audioStartedAtMs);

            $this->markJobRunning($job, 'optimizing-audio', 35);
            OptimizeSubtitleAudio::dispatch($job->id, $runId, $audio)
                ->onQueue(SubtitleQueue::generationNameForJob($job));
        } catch (Throwable $exception) {
            SubtitleAudioWorkspace::delete($runId);
            $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId);

            throw $exception;
        }
    }

    /**
     * Generation stage 2: normalize the audio for Scribe, split it into
     * chunks, and fan the chunks out as a generation-family batch whose
     * completion merges the transcript. Audio too short to chunk rides the
     * same path as a single whole-file chunk.
     */
    public function optimizeAudioAndDispatchTranscription(
        int $subtitleJobId,
        string $runId,
        TemporaryAudioFile $audio,
        ?int $queuedAtMs = null,
    ): void {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            SubtitleAudioWorkspace::delete($runId);

            return;
        }

        $this->telemetry->recordQueueWait($job, 'optimizing-audio', null, $queuedAtMs);

        $stage = 'optimizing-audio';

        try {
            $this->telemetry->recordStageStarted($job, 'optimizing-audio');

            $audioOptimizationStartedAtMs = $this->telemetry->currentTimeMs();
            $preparedAudio = $this->transcriptionService->prepareAudio($audio);
            $this->telemetry->recordStageCompleted($job, 'optimizing-audio', $audioOptimizationStartedAtMs);

            $stage = 'transcribing';
            $chunkPlan = $this->chunker->plan($preparedAudio->durationSeconds);
            $chunks = $chunkPlan === []
                ? [[
                    'file' => $preparedAudio,
                    'audioStartSeconds' => 0.0,
                    'nominalStartSeconds' => 0.0,
                    'nominalEndSeconds' => null,
                ]]
                : $this->chunkFiles($preparedAudio, $chunkPlan);

            $this->markJobRunning($job, 'transcribing', 50);
            $job = $job->refresh();
            $this->logger->transcriptionStarted($job);
            $this->telemetry->recordStageStarted($job, 'transcribing');

            $chunkCount = count($chunks);
            $chunkJobs = [];

            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkJobs[] = new TranscribeSubtitleAudioChunk(
                    subtitleJobId: $job->id,
                    chunkIndex: $chunkIndex,
                    chunkCount: $chunkCount,
                    runId: $runId,
                    chunkAudio: $chunk['file'],
                    audioStartSeconds: $chunk['audioStartSeconds'],
                    nominalStartSeconds: $chunk['nominalStartSeconds'],
                    nominalEndSeconds: $chunk['nominalEndSeconds'],
                );
            }

            $this->batchDispatcher->dispatchTranscription($job, $chunkJobs, $this->telemetry->currentTimeMs());
        } catch (Throwable $exception) {
            SubtitleAudioWorkspace::delete($runId);
            $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId);

            throw $exception;
        }
    }

    /**
     * Generation stage 3, one queue job per chunk: upload the chunk to
     * Scribe and store the raw payload for the merge stage.
     */
    public function transcribeAudioChunk(
        int $subtitleJobId,
        string $runId,
        int $chunkIndex,
        int $chunkCount,
        TemporaryAudioFile $chunkAudio,
        float $audioStartSeconds,
        float $nominalStartSeconds,
        ?float $nominalEndSeconds,
        ?int $queuedAtMs = null,
    ): void {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'transcribing', null, $queuedAtMs);

        $payload = $this->transcriptionService->transcribeChunk($chunkAudio, $job->source_language);

        $this->artifacts->putTranscriptChunk(
            job: $job,
            chunkIndex: $chunkIndex,
            chunkCount: $chunkCount,
            payload: $payload,
            audioStartSeconds: $audioStartSeconds,
            nominalStartSeconds: $nominalStartSeconds,
            nominalEndSeconds: $nominalEndSeconds,
        );
    }

    /**
     * Generation stage 4 (transcription batch completion): merge the chunk
     * payloads into one normalized transcript, store the draft cues, and
     * dispatch the analysis batches. The audio workspace is finished after
     * this point and is removed on success and failure alike.
     */
    public function mergeTranscriptAndDispatchAnalysis(
        int $subtitleJobId,
        string $runId,
        int $transcribingStartedAtMs,
        ?int $queuedAtMs = null,
    ): void {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            SubtitleAudioWorkspace::delete($runId);

            return;
        }

        $this->telemetry->recordQueueWait($job, 'transcribing', null, $queuedAtMs);

        try {
            $durationSeconds = (int) $job->video_duration_seconds;
            $transcript = $this->transcriptionService->transcriptFromChunkPayloads(
                chunks: $this->artifacts->transcriptChunks($job),
                sourceLanguage: $job->source_language,
                durationSeconds: $durationSeconds,
            );

            $this->telemetry->recordStageCompleted($job, 'transcribing', $transcribingStartedAtMs);
            $this->costs->recordTranscription($job, $durationSeconds);

            $this->logger->transcriptionCompleted($job, $transcript, $durationSeconds);
            $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);
            $this->transcriptCache->store(
                youtubeVideoId: $job->youtube_video_id,
                requestedSourceLanguage: $job->source_language,
                transcript: $transcript,
                audioDurationSeconds: $durationSeconds,
            );

            $job = $job->refresh();
            $draftCues = $this->tracks->draftCues($transcript);

            $this->artifacts->putTranscript($job, $transcript);
            $this->artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $draftCues);
            $this->telemetry->recordFirstCueAvailable($job);

            $this->dispatchTokenizationAndTranslationBatches($job);
        } catch (Throwable $exception) {
            $this->failureHandler->failJob($subtitleJobId, 'transcribing', $exception, $runId);

            throw $exception;
        } finally {
            SubtitleAudioWorkspace::delete($runId);
        }
    }

    /**
     * @param  array<int, array{nominalStart: float, nominalEnd: float, audioStart: float, audioEnd: float}>  $chunkPlan
     * @return array<int, array{file: TemporaryAudioFile, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}>
     */
    private function chunkFiles(TemporaryAudioFile $preparedAudio, array $chunkPlan): array
    {
        $chunkAudioFiles = $this->chunker->split($preparedAudio, $chunkPlan);
        $lastChunkIndex = array_key_last($chunkPlan);
        $chunks = [];

        foreach ($chunkPlan as $index => $bounds) {
            $chunks[] = [
                'file' => $chunkAudioFiles[$index],
                'audioStartSeconds' => $bounds['audioStart'],
                'nominalStartSeconds' => $bounds['nominalStart'],
                // The last chunk keeps everything past its nominal start.
                'nominalEndSeconds' => $index === $lastChunkIndex ? null : $bounds['nominalEnd'],
            ];
        }

        return $chunks;
    }

    /**
     * Cache-hit continuation: the transcript already exists for this video,
     * so the job goes straight from claiming to analysis dispatch. Billing
     * still syncs the reservation to the cached duration, but no provider
     * transcription cost is recorded -- no provider call happened.
     */
    private function continueWithCachedTranscript(SubtitleJob $job, CachedVideoTranscript $cached): void
    {
        $transcript = $this->transcriptCache->transcript($cached);

        $this->telemetry->recordTranscriptCacheHit($job);

        $job->update(['video_duration_seconds' => $cached->audio_duration_seconds]);
        $job = $job->refresh()->load('user');
        $this->billing->syncJobReservationToActualDuration($job);

        $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);

        $job = $job->refresh();
        $draftCues = $this->tracks->draftCues($transcript);

        $this->artifacts->putTranscript($job, $transcript);
        $this->artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $draftCues);
        $this->telemetry->recordFirstCueAvailable($job);

        $this->dispatchTokenizationAndTranslationBatches($job);
    }

    public function prepareCuesAfterCompletedAnalysisBatches(int $subtitleJobId, string $runId, ?int $queuedAtMs = null): void
    {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $stage = 'assembling-analysis-results';
        $this->telemetry->recordQueueWait($job, $stage, null, $queuedAtMs);
        $this->telemetry->recordStageStarted($job, $stage);
        $startedAtMs = $this->telemetry->currentTimeMs();

        $tokenized = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TOKENIZED_CUES);
        $this->logger->tokenizationCompleted($job, $tokenized);

        // Romanization now runs chained after each tokenize batch inside the
        // analysis batch, so its cues are already written by the time this
        // continuation fires -- no separate romanization batch or queue hop.
        $base = $tokenized;

        if ($this->shouldRomanize($job)) {
            $romanized = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ROMANIZED_CUES);
            $this->logger->romanizationCompleted($job, $romanized);
            $base = $romanized;
        }

        $translated = null;

        if ($this->translationRequested($job)) {
            $translated = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::TRANSLATED_CUES);
            $this->logger->translationCompleted($job, $translated);
        }

        $this->storeMergedCuesAndContinue($job, $base, $translated);
        $this->telemetry->recordStageCompleted($job, $stage, $startedAtMs);
    }

    public function persistGeneratedSubtitleTrack(
        int $subtitleJobId,
        bool $useEnrichedCues,
        string $runId,
        ?int $queuedAtMs = null,
    ): void {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'finalizing', null, $queuedAtMs);
        $this->telemetry->recordStageStarted($job, 'finalizing');
        $startedAtMs = $this->telemetry->currentTimeMs();

        $transcript = $this->artifacts->transcript($job);
        $enrichment = $useEnrichedCues
            ? $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ENRICHED_CUES)
            : $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::MERGED_CUES);

        if ($useEnrichedCues) {
            $this->logger->enrichmentCompleted($job, $enrichment);
        }

        $this->markJobRunning($job, 'finalizing', 95);
        $job = $job->refresh();

        $track = DB::transaction(function () use ($job, $transcript, $enrichment) {
            $job->track()->delete();
            $track = $this->tracks->generate($job, $transcript, $enrichment);
            $job->update([
                'status' => 'completed',
                'stage' => 'finalizing',
                'progress_percent' => 100,
                'error_code' => null,
                'error_message' => null,
                'expires_at' => $track->expires_at,
            ]);
            $completedJob = $job->refresh()->load('user');
            $this->usageLedger->debitCompletedJob($completedJob, $track);
            $this->artifacts->deleteForJob($completedJob);

            return $track;
        });

        $job = $job->refresh()->load('track');
        $this->logger->trackGenerated(
            job: $job,
            track: $track,
            audioDurationSeconds: (int) ($job->video_duration_seconds ?? $transcript->durationSeconds ?? 0),
        );
        $this->telemetry->recordStageCompleted($job, 'finalizing', $startedAtMs);
        $this->logger->completedTrackTiming($job, (int) abs(now()->diffInMilliseconds($job->created_at)));
        $this->telemetry->recordJobCompleted($job);
        $this->admission->promoteQueuedJobs($job->user_id);
    }

    private function dispatchTokenizationAndTranslationBatches(SubtitleJob $job): void
    {
        $this->markJobRunning($job, 'tokenizing', 65);
        $cueCount = $this->artifacts->cueCount($job, SubtitleJobArtifactStore::DRAFT_CUES);
        $translationRequested = $this->translationRequested($job);
        $romanize = $this->shouldRomanize($job);

        $this->logger->tokenizationStarted($job, $cueCount);

        if ($translationRequested) {
            $this->logger->translationStarted($job, $cueCount);
        }

        if ($romanize) {
            $this->logger->romanizationStarted($job, $cueCount);
        }

        $jobs = [];
        $batchCount = $this->artifacts->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES);

        for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {
            // One merged tokenize+translate call per batch when translation is
            // requested; tokenize-only otherwise. Romanization only needs its
            // own batch's tokenized output, so chain Romanize(N) directly after
            // the analysis job. The batch completes once every member -- chains
            // included -- finishes.
            $analysisJob = $translationRequested
                ? new AnalyzeSubtitleCueBatch($job->id, $batchIndex, $job->run_id)
                : new TokenizeSubtitleCueBatch($job->id, $batchIndex, $job->run_id);

            $jobs[] = $romanize
                ? [$analysisJob, new RomanizeSubtitleCueBatch($job->id, $batchIndex, $job->run_id)]
                : $analysisJob;
        }

        $this->batchDispatcher->dispatchAnalysis($job, $jobs);
    }

    private function dispatchWordCardEnrichmentBatches(SubtitleJob $job): void
    {
        $this->markJobRunning($job, 'enriching', 90);
        $cueCount = $this->artifacts->cueCount($job, SubtitleJobArtifactStore::MERGED_CUES);
        $this->logger->enrichmentStarted($job, $cueCount);

        $jobs = [];
        $batchCount = $this->artifacts->batchCount($job, SubtitleJobArtifactStore::MERGED_CUES);

        for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {
            $jobs[] = new EnrichSubtitleCueBatch($job->id, $batchIndex, $job->run_id);
        }

        $this->batchDispatcher->dispatchEnrichment($job, $jobs);
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
            $this->dispatchWordCardEnrichmentBatches($job);

            return;
        }

        $this->batchDispatcher->dispatchMergedCueTrackFinalization($job);
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

    private function claimPreparingJob(int $subtitleJobId, string $runId): ?SubtitleJob
    {
        $updated = SubtitleJob::query()
            ->whereKey($subtitleJobId)
            ->where('status', 'running')
            ->where('stage', 'preparing')
            ->where('run_id', $runId)
            ->update([
                'stage' => 'acquiring-audio',
                'progress_percent' => 20,
                'error_code' => null,
                'error_message' => null,
            ]);

        if ($updated !== 1) {
            $job = SubtitleJob::query()->find($subtitleJobId);

            if ($job !== null && $job->run_id !== $runId) {
                $this->telemetry->recordStaleRunSkipped($job, $runId, 'acquiring-audio');
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
     * Decide romanization once, at analysis-batch dispatch time, so Romanize(N)
     * can be chained onto Tokenize(N). The draft cues carry the same sourceText
     * as the tokenized cues, so the decision is identical whether it reads draft
     * or tokenized output -- and the draft artifact exists before tokenization.
     */
    private function shouldRomanize(SubtitleJob $job): bool
    {
        if (! $job->include_romanization) {
            return false;
        }

        $draftCues = $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues;

        return $this->shouldRomanizeTranscript($draftCues);
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

    private function isSameLanguageGeneration(SubtitleJob $job): bool
    {
        return $job->effectiveSourceLanguage() === $job->target_language;
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

        set_time_limit((int) config('subtitles.processing_timeout_seconds', 0));
    }
}
