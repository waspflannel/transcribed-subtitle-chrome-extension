<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AnalyzeSubtitleCueBatch;
use App\Jobs\OptimizeSubtitleAudio;
use App\Jobs\PrepareSubtitleCuesAfterAnalysisBatches;
use App\Jobs\TranscribeSubtitleAudioChunk;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Services\Audio\ScribeAudioChunker;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\InstanceSettings;
use App\Services\Languages\LanguageCatalog;
use App\Services\Text\SubtitleText;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\VideoTranscriptCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    ) {}

    /**
     * Generation stage 1: claim the preparing job and download the source
     * audio, then hand off to OptimizeSubtitleAudio. A cached transcript
     * skips the audio stages entirely and dispatches analysis directly.
     */
    public function acquireAudioAndContinue(int $subtitleJobId, string $runId, ?int $queuedAtMs = null): void
    {
        $job = $this->claimAcquisition($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'acquiring-audio', null, $queuedAtMs);

        $stage = 'acquiring-audio';

        try {
            // A cached transcript for this video makes acquire, optimize, and
            // transcribe unnecessary -- roughly 45% of a job's wall time.
            $cached = $this->transcriptCache->find($job->youtube_video_id, $job->source_language,
                $job->transcription_ingestion_mode);

            if ($cached !== null) {
                $stage = 'transcribing';
                $this->continueWithCachedTranscript($job, $cached);

                return;
            }

            $this->telemetry->recordStageStarted($job, 'acquiring-audio');
            $this->logger->audioAcquisitionStarted($job);

            $audioStartedAtMs = $this->telemetry->currentTimeMs();
            if ($job->transcription_ingestion_mode === 'youtube_url') {
                $this->dispatchUrlTranscription($job, $audioStartedAtMs);

                return;
            }
            $audio = $this->audioSource->acquire(
                youtubeUrl: $job->youtube_url,
                requestDurationSeconds: $job->video_duration_seconds,
                workDirectory: SubtitleAudioWorkspace::directory($runId),
                videoId: $job->youtube_video_id,
            );

            $continued = DB::transaction(function () use ($subtitleJobId, $runId, $audio, $audioStartedAtMs): bool {
                $currentJob = $this->lockRunningJob($subtitleJobId, $runId);

                if (! $currentJob instanceof SubtitleJob) {
                    return false;
                }

                $currentJob->update(['video_duration_seconds' => $audio->durationSeconds]);
                $this->logger->audioAcquisitionCompleted($currentJob, $audio);
                $this->telemetry->recordStageCompleted($currentJob, 'acquiring-audio', $audioStartedAtMs);
                $this->markJobRunning($currentJob, 'optimizing-audio', 35);

                OptimizeSubtitleAudio::dispatch($currentJob->id, $runId, $audio)
                    ->onQueue(SubtitleQueue::generationName())
                    ->afterCommit();

                return true;
            }, attempts: 5);

            if (! $continued) {
                SubtitleAudioWorkspace::delete($runId);
            }
        } catch (Throwable $exception) {
            SubtitleAudioWorkspace::delete($runId);
            $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId);

            throw $exception;
        }
    }

    private function dispatchUrlTranscription(SubtitleJob $job, int $startedAtMs): void
    {
        $duration = $this->audioSource->validatedDuration(
            'https://www.youtube.com/watch?v='.$job->youtube_video_id, $job->video_duration_seconds);

        DB::transaction(function () use ($job, $duration, $startedAtMs): void {
            $currentJob = $this->lockRunningJob($job->id, $job->run_id);
            if ($currentJob === null) {
                return;
            }
            $currentJob->update(['video_duration_seconds' => $duration]);
            $this->telemetry->recordStageCompleted($currentJob, 'acquiring-audio', $startedAtMs);
            $this->markJobRunning($currentJob, 'transcribing', 50);
            $this->logger->transcriptionStarted($currentJob);
            $this->telemetry->recordStageStarted($currentJob, 'transcribing');
            $transcribingStartedAtMs = $this->telemetry->currentTimeMs();
            $chunks = [new TranscribeSubtitleAudioChunk($currentJob->id, 0, 1, $currentJob->run_id, null, 0.0, 0.0, null)];
            DB::afterCommit(fn () => $this->batchDispatcher->dispatchTranscription($currentJob, $chunks, $transcribingStartedAtMs));
        }, attempts: 5);
    }

    /**
     * Generation stage 2: normalize the audio for Scribe, plan its chunks,
     * and fan out extraction/upload jobs as a generation-family batch whose
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

        if ($job->stage === 'transcribing') {
            $this->dispatchPlannedTranscription($job);

            return;
        }
        if ($job->stage !== 'optimizing-audio') {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'optimizing-audio', null, $queuedAtMs);

        $stage = 'optimizing-audio';

        try {
            $this->telemetry->recordStageStarted($job, 'optimizing-audio');

            $audioOptimizationStartedAtMs = $this->telemetry->currentTimeMs();
            // Direct seeking shifts WebM/Opus audio by its codec delay. Keep
            // that source on the whole-file normalization path.
            $directChunks = config('subtitles.audio_preparation.direct_chunks', false)
                && $audio->mimeType === 'audio/mp4'
                && $this->chunker->plan($audio->durationSeconds) !== [];
            if ($directChunks) {
                $this->transcriptionService->assertAudioCanBePrepared($audio);
            }
            $preparedAudio = $directChunks ? $audio : $this->transcriptionService->prepareAudio($audio);
            $stage = 'transcribing';
            $chunkPlan = $this->chunker->plan($preparedAudio->durationSeconds);
            $chunks = $chunkPlan === []
                ? [['audioStart' => 0.0, 'nominalStart' => 0.0, 'nominalEnd' => null, 'audioEnd' => null]]
                : $chunkPlan;

            $chunkCount = count($chunks);
            $chunkJobs = [];

            foreach ($chunks as $chunkIndex => $chunk) {
                $chunkJobs[] = new TranscribeSubtitleAudioChunk(
                    subtitleJobId: $subtitleJobId,
                    chunkIndex: $chunkIndex,
                    chunkCount: $chunkCount,
                    runId: $runId,
                    chunkAudio: $preparedAudio,
                    audioStartSeconds: $chunk['audioStart'],
                    nominalStartSeconds: $chunk['nominalStart'],
                    nominalEndSeconds: $chunkIndex === $chunkCount - 1 ? null : $chunk['nominalEnd'],
                    nextAudioStartSeconds: $chunkPlan[$chunkIndex + 1]['audioStart'] ?? null,
                    audioEndSeconds: $chunk['audioEnd'],
                );
            }

            $continued = DB::transaction(function () use (
                $subtitleJobId,
                $runId,
                $preparedAudio,
                $chunkPlan,
                $chunks,
                $chunkJobs,
                $audioOptimizationStartedAtMs,
            ): bool {
                $currentJob = $this->lockRunningJob($subtitleJobId, $runId);

                if (! $currentJob instanceof SubtitleJob || $currentJob->stage !== 'optimizing-audio') {
                    return false;
                }

                $this->telemetry->recordStageCompleted($currentJob, 'optimizing-audio', $audioOptimizationStartedAtMs);

                if ($chunkPlan !== []) {
                    Log::info('backend.transcription_chunked', [
                        'job_id' => $currentJob->public_id,
                        'audio_duration_seconds' => $preparedAudio->durationSeconds,
                        'chunk_count' => count($chunks),
                    ]);
                }

                $this->markJobRunning($currentJob, 'transcribing', 50);
                $this->logger->transcriptionStarted($currentJob);
                $this->telemetry->recordStageStarted($currentJob, 'transcribing');
                $transcribingStartedAtMs = $this->telemetry->currentTimeMs();
                $this->artifacts->putTranscriptionPlan($currentJob, [
                    'audio' => get_object_vars($preparedAudio),
                    'chunks' => array_map(fn (TranscribeSubtitleAudioChunk $chunk): array => [
                        'audioStartSeconds' => $chunk->audioStartSeconds,
                        'nominalStartSeconds' => $chunk->nominalStartSeconds,
                        'nominalEndSeconds' => $chunk->nominalEndSeconds,
                        'nextAudioStartSeconds' => $chunk->nextAudioStartSeconds,
                        'audioEndSeconds' => $chunk->audioEndSeconds,
                    ], $chunkJobs),
                    'startedAtMs' => $transcribingStartedAtMs,
                    'dispatched' => false,
                ]);
                DB::afterCommit(fn () => $this->dispatchPlannedTranscription($currentJob));

                return true;
            }, attempts: 5);

            if (! $continued && $this->loadRunningJob($subtitleJobId, $runId) === null) {
                SubtitleAudioWorkspace::delete($runId);
            }
        } catch (Throwable $exception) {
            SubtitleAudioWorkspace::delete($runId);
            $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId);

            throw $exception;
        }
    }

    private function dispatchPlannedTranscription(SubtitleJob $job): void
    {
        $job = $this->loadRunningJob($job->id, $job->run_id);
        if ($job === null || $job->stage !== 'transcribing') {
            return;
        }
        $plan = $this->artifacts->transcriptionPlan($job);
        if ($plan === null || $plan['dispatched']) {
            return;
        }
        $audio = new TemporaryAudioFile(...$plan['audio']);
        $chunks = [];
        foreach ($plan['chunks'] as $index => $bounds) {
            $chunks[] = new TranscribeSubtitleAudioChunk(
                ...$bounds,
                subtitleJobId: $job->id, chunkIndex: $index, chunkCount: count($plan['chunks']),
                runId: $job->run_id, chunkAudio: $audio,
            );
        }
        // A crash after publishing may replay this batch; chunk locks and artifacts
        // make duplicate members harmless. The plan recovers a lost publication.
        $this->batchDispatcher->dispatchTranscription($job, $chunks, $plan['startedAtMs']);
        $this->artifacts->putTranscriptionPlan($job, [...$plan, 'dispatched' => true]);
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
        ?TemporaryAudioFile $chunkAudio,
        float $audioStartSeconds,
        float $nominalStartSeconds,
        ?float $nominalEndSeconds,
        ?int $queuedAtMs = null,
        ?float $nextAudioStartSeconds = null,
        ?float $audioEndSeconds = null,
    ): void {
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        if ($this->artifacts->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT_CHUNK, $chunkIndex)) {
            $this->publishTranscribedPrefix($subtitleJobId, $runId);

            return;
        }

        $job = DB::transaction(function () use ($subtitleJobId, $runId, $chunkIndex): ?SubtitleJob {
            $job = $this->lockRunningJob($subtitleJobId, $runId);
            if ($job === null || $this->artifacts->hasArtifact($job, SubtitleJobArtifactStore::TRANSCRIPT_CHUNK, $chunkIndex)) {
                return null;
            }

            // Each bounded retry gets its own watchdog window; completed
            // chunk redelivery must not extend an abandoned run's lifetime.
            $job->touch();

            return $job;
        }, attempts: 5);

        if ($job === null) {
            return;
        }

        $this->telemetry->recordQueueWait($job, 'transcribing', null, $queuedAtMs);

        if ($chunkAudio !== null && $audioEndSeconds !== null) {
            $preparationStartedAtMs = $this->telemetry->currentTimeMs();
            $chunkAudio = $this->chunker->extractChunk($chunkAudio, $chunkIndex, $audioStartSeconds, $audioEndSeconds);
            $job = $this->loadRunningJob($subtitleJobId, $runId);
            if ($job === null) {
                return;
            }
            $this->telemetry->recordAudioChunkPrepared($job, $chunkIndex, $preparationStartedAtMs, $chunkAudio->sizeBytes);
        }

        $requestStartedAtMs = $this->telemetry->currentTimeMs();
        if ($chunkAudio === null) {
            if ($job->transcription_ingestion_mode !== 'youtube_url' || $chunkIndex !== 0 || $chunkCount !== 1
                || $audioStartSeconds !== 0.0 || $nominalStartSeconds !== 0.0 || $nominalEndSeconds !== null) {
                throw SubtitleProcessingException::transcriptionFailed(context: ['reason' => 'invalid_url_transcription_chunk']);
            }
            $payload = $this->transcriptionService->transcribeYouTube($job->youtube_video_id, $job->source_language, job: $job);
        } else {
            $payload = $this->transcriptionService->transcribeChunk($chunkAudio, $job->source_language, job: $job);
        }

        $this->telemetry->recordTranscriptionChunkCompleted($job, $chunkIndex, $requestStartedAtMs, $chunkAudio?->sizeBytes);
        $job = $this->loadRunningJob($subtitleJobId, $runId);

        if ($job === null) {
            return;
        }

        $this->artifacts->putTranscriptChunk(
            job: $job,
            chunkIndex: $chunkIndex,
            chunkCount: $chunkCount,
            payload: $payload,
            audioStartSeconds: $audioStartSeconds,
            nominalStartSeconds: $nominalStartSeconds,
            nominalEndSeconds: $nominalEndSeconds,
            nextAudioStartSeconds: $nextAudioStartSeconds,
        );
        $this->publishTranscribedPrefix($subtitleJobId, $runId);
    }

    private function publishTranscribedPrefix(int $subtitleJobId, string $runId): void
    {
        DB::transaction(function () use ($subtitleJobId, $runId): void {
            $job = $this->lockRunningJob($subtitleJobId, $runId);
            if ($job === null || $job->stage !== 'transcribing') {
                return;
            }
            $transcript = $this->transcriptionService->stableTranscriptPrefix(
                $this->artifacts->transcriptChunks($job, contiguousPrefix: true),
                $job->source_language,
                (int) $job->video_duration_seconds,
            );
            if ($transcript === null || $transcript->segments === []) {
                return;
            }
            // This label describes the available audio prefix. AI requests keep
            // the requested language so an intro cannot lock later cues.
            $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);
            $indexes = $this->artifacts->appendDraftCues($job, $this->tracks->draftCues($transcript));
            if ($indexes === []) {
                return;
            }
            if ($indexes[0] === 0) {
                $this->telemetry->recordFirstCueAvailable($job);
            }
            $this->dispatchAnalysisIndexes($job, $indexes);
        }, attempts: 5);
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
            if ($job->stage === 'tokenizing') {
                // A crash right after the merge commit can lose its analysis
                // dispatch. Replaying pending batches is safe: analysis locks
                // and stored results drop duplicates.
                $this->dispatchAnalysisIndexes($job, $this->artifacts->pendingAnalysisIndexes($job));

                return;
            }

            $durationSeconds = (int) $job->video_duration_seconds;
            $transcript = $this->transcriptionService->transcriptFromChunkPayloads(
                chunks: $this->artifacts->transcriptChunks($job),
                sourceLanguage: $job->source_language,
                durationSeconds: $durationSeconds,
                jobId: $job->public_id,
                runId: $job->run_id,
            );
            $draftCues = $this->tracks->draftCues($transcript);

            $merged = DB::transaction(function () use ($subtitleJobId, $runId, $transcript, $draftCues, $durationSeconds, $transcribingStartedAtMs): bool {
                $job = $this->lockRunningJob($subtitleJobId, $runId);
                if ($job === null || $job->stage !== 'transcribing') {
                    return false;
                }
                $this->failWithoutSpeech($draftCues);
                $this->telemetry->recordStageCompleted($job, 'transcribing', $transcribingStartedAtMs);
                $this->costs->recordTranscription($job, $durationSeconds);
                $this->logger->transcriptionCompleted($job, $transcript, $durationSeconds);
                $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);
                $this->artifacts->putTranscript($job, $transcript);
                $indexes = $this->artifacts->appendDraftCues($job, $draftCues);
                if (in_array(0, $indexes, true)) {
                    $this->telemetry->recordFirstCueAvailable($job);
                }
                $this->dispatchAnalysisBatches($job);

                return true;
            }, attempts: 5);

            if ($merged) {
                $this->cacheTranscript($job, $transcript, $durationSeconds);
            }
        } catch (Throwable $exception) {
            $this->failureHandler->failJob($subtitleJobId, 'transcribing', $exception, $runId);

            throw $exception;
        } finally {
            SubtitleAudioWorkspace::delete($runId);
        }
    }

    /**
     * Cache-hit continuation: the transcript already exists for this video,
     * so the job goes straight from claiming to analysis dispatch.
     * No transcription cost is recorded because no provider call happened.
     */
    private function continueWithCachedTranscript(SubtitleJob $job, CachedVideoTranscript $cached): void
    {
        $transcript = $this->transcriptCache->transcript($cached);
        $draftCues = $this->tracks->draftCues($transcript);

        DB::transaction(function () use ($job, $cached, $transcript, $draftCues): void {
            $job = $this->lockRunningJob($job->id, $job->run_id);

            if ($job === null || $job->stage !== 'acquiring-audio') {
                return;
            }
            $this->failWithoutSpeech($draftCues);

            $this->telemetry->recordTranscriptCacheHit($job);
            $job->update(['video_duration_seconds' => $cached->audio_duration_seconds]);
            $this->recordDetectedSourceLanguage($job, $job->source_language, $transcript->language);
            $this->artifacts->putTranscript($job, $transcript);
            $this->artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $draftCues);
            $this->telemetry->recordFirstCueAvailable($job);
            $this->dispatchAnalysisBatches($job);
        }, attempts: 5);
    }

    /**
     * Analysis batch completion: once every batch has landed, assemble the
     * analyzed cues and publish the track in one locked transaction. A crash
     * before commit leaves the run in `tokenizing` with its artifacts, so the
     * redelivered continuation publishes it again.
     */
    public function prepareCuesAfterCompletedAnalysisBatches(int $subtitleJobId, string $runId, ?int $queuedAtMs = null): void
    {
        if ($this->loadRunningJob($subtitleJobId, $runId)?->stage !== 'tokenizing') {
            return;
        }

        $stage = 'tokenizing';

        try {
            $published = DB::transaction(function () use ($subtitleJobId, $runId, $queuedAtMs, &$stage): ?array {
                // Retention changes and publication share settings -> job -> track order.
                app(InstanceSettings::class)->lockForUpdate();
                $job = $this->lockRunningJob($subtitleJobId, $runId);
                if ($job === null || $job->stage !== 'tokenizing' || ! $this->artifacts->analysisIsComplete($job)) {
                    return null;
                }
                $this->telemetry->recordQueueWait($job, 'assembling-analysis-results', null, $queuedAtMs);
                $this->telemetry->recordStageStarted($job, 'assembling-analysis-results');
                $startedAtMs = $this->telemetry->currentTimeMs();
                $analyzed = $this->artifacts->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ANALYZED_CUES);
                $this->logger->tokenizationCompleted($job, $analyzed);
                $this->telemetry->recordStageCompleted($job, 'assembling-analysis-results', $startedAtMs);

                $stage = 'finalizing';
                $this->telemetry->recordStageStarted($job, $stage);
                $startedAtMs = $this->telemetry->currentTimeMs();
                $audioDurationSeconds = (int) ($job->video_duration_seconds
                    ?? $this->artifacts->transcript($job)->durationSeconds ?? 0);
                $job->track()->delete();
                $track = $this->tracks->generate($job, $analyzed);
                $job->update([
                    'status' => 'completed',
                    'stage' => 'finalizing',
                    'progress_percent' => 100,
                    'error_code' => null,
                    'error_message' => null,
                    'expires_at' => $track->expires_at,
                ]);
                $this->artifacts->deleteForJob($job);

                return [$job, $track, $startedAtMs, $audioDurationSeconds];
            }, attempts: 5);
        } catch (Throwable $exception) {
            $this->failureHandler->failJob($subtitleJobId, $stage, $exception, $runId);

            throw $exception;
        }

        if ($published === null) {
            return;
        }

        [$job, $track, $startedAtMs, $audioDurationSeconds] = $published;
        $job->setRelation('track', $track);
        $this->logger->trackGenerated(job: $job, track: $track, audioDurationSeconds: $audioDurationSeconds);
        $this->telemetry->recordStageCompleted($job, 'finalizing', $startedAtMs);
        $this->logger->completedTrackTiming($job, (int) abs(now()->diffInMilliseconds($job->created_at)));
        $this->telemetry->recordJobCompleted($job);
    }

    private function dispatchAnalysisBatches(SubtitleJob $job): void
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

        // Reconcile early dispatches as well as new cues. A worker can die
        // after committing a prefix but before publishing its queue batch.
        // Existing analysis locks and result checks make redelivery safe.
        $indexes = $this->artifacts->pendingAnalysisIndexes($job);
        $this->dispatchAnalysisIndexes($job, $indexes);
    }

    private function dispatchAnalysisIndexes(SubtitleJob $job, array $indexes): void
    {
        if ($indexes === []) {
            PrepareSubtitleCuesAfterAnalysisBatches::dispatch($job->id, $job->run_id)
                ->onQueue(SubtitleQueue::batchName())->afterCommit();

            return;
        }
        $jobs = array_map(fn (int $index): AnalyzeSubtitleCueBatch => new AnalyzeSubtitleCueBatch($job->id, $index, $job->run_id), $indexes);
        DB::afterCommit(fn () => $this->batchDispatcher->dispatchAnalysis($job, $jobs));
    }

    /**
     * Sound symbols such as "♪" produce no draft cues, so a whole transcript
     * without cues has no speech to subtitle.
     *
     * @param  array<int, array<string, mixed>>  $draftCues
     */
    private function failWithoutSpeech(array $draftCues): void
    {
        if ($draftCues === []) {
            throw SubtitleProcessingException::transcriptionFailed('No speech was found in this video.', [
                'reason' => 'no_speech',
            ]);
        }
    }

    /**
     * Runs after the merge commit. The cache only saves a future transcription,
     * so a failed write must not fail a run that already paid for Scribe.
     */
    private function cacheTranscript(SubtitleJob $job, TimestampedTranscript $transcript, int $durationSeconds): void
    {
        try {
            $this->transcriptCache->store(
                youtubeVideoId: $job->youtube_video_id,
                requestedSourceLanguage: $job->source_language,
                transcript: $transcript,
                audioDurationSeconds: $durationSeconds,
                ingestionMode: $job->transcription_ingestion_mode,
            );
        } catch (Throwable $exception) {
            Log::warning('backend.transcript_cache_write_failed', [
                'job_id' => $job->public_id,
                'run_id' => $job->run_id,
                'exception' => $exception::class,
            ]);
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

    private function lockRunningJob(int $subtitleJobId, string $runId): ?SubtitleJob
    {
        $job = SubtitleJobLock::current($subtitleJobId, $runId);

        if (
            ! $job instanceof SubtitleJob
            || $job->run_id !== $runId
            || $job->status !== 'running'
            || $job->hasReadyTrack()
        ) {
            return null;
        }

        return $job;
    }

    /**
     * Claims the run for audio acquisition and refreshes its watchdog clock.
     * A redelivery after a worker crash re-claims `acquiring-audio` for the
     * same run: the per-run workspace makes downloading again safe, and the
     * job's run lock keeps two deliveries from overlapping.
     */
    private function claimAcquisition(int $subtitleJobId, string $runId): ?SubtitleJob
    {
        $updated = SubtitleJob::query()
            ->whereKey($subtitleJobId)
            ->where('status', 'running')
            ->whereIn('stage', ['preparing', 'acquiring-audio'])
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

    private function shouldRomanize(SubtitleJob $job): bool
    {
        if (! $job->include_romanization) {
            return false;
        }

        $draftCues = $this->artifacts->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues;

        return SubtitleText::hasNonLatinCues($draftCues);
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
        return $job->source_language === $job->target_language;
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
}
