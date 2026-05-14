<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Languages\LanguageCatalog;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SubtitleJobService
{
    public const PROCESSING_VERSION_ON_DEMAND = 'elevenlabs-scribe-v2-transcript-first-agent-tokenizer-v6';

    public const PROCESSING_VERSION_ON_DEMAND_ROMANIZED = 'elevenlabs-scribe-v2-transcript-first-agent-tokenizer-v6-romanized';

    public const PROCESSING_VERSION_FULL = 'elevenlabs-scribe-v2-full-agent-tokenizer-v6';

    public const PROCESSING_VERSION_FULL_ROMANIZED = 'elevenlabs-scribe-v2-full-agent-tokenizer-v6-romanized';

    public const CURRENT_PROCESSING_VERSIONS = [
        self::PROCESSING_VERSION_ON_DEMAND,
        self::PROCESSING_VERSION_ON_DEMAND_ROMANIZED,
        self::PROCESSING_VERSION_FULL,
        self::PROCESSING_VERSION_FULL_ROMANIZED,
    ];

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly ElevenLabsScribeTranscriptionService $transcriptionService,
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
        private readonly SubtitleWorkflowLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        $enrichmentMode = $payload['enrichmentMode'];
        $includeRomanization = $payload['includeRomanization'];
        $processingVersion = $this->processingVersion($enrichmentMode, $includeRomanization);

        $job = DB::transaction(function () use ($payload, $installId, $requestIp, $processingVersion): SubtitleJob {
            $job = SubtitleJob::query()
                ->with('track')
                ->where('youtube_video_id', $payload['youtubeVideoId'])
                ->where('source_language', $payload['sourceLanguage'])
                ->where('target_language', $payload['targetLanguage'])
                ->where('processing_version', $processingVersion)
                ->where('install_id', $installId)
                ->first();

            if ($job) {
                if ($this->hasReadyTrack($job)) {
                    return $job;
                }

                $this->logger->incompleteJobReused($job);
                $this->resetJob($job, $payload, $installId, $requestIp);
            } else {
                $job = $this->createJob($payload, $installId, $requestIp, $processingVersion);
                $this->logger->jobCreated($job);
            }

            return $job->refresh();
        });

        if ($this->hasReadyTrack($job->load('track'))) {
            $this->logger->trackReused($job);

            return $job;
        }

        return $this->generateTrack($job, $payload, $enrichmentMode, $includeRomanization);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function generateTrack(
        SubtitleJob $job,
        array $payload,
        string $enrichmentMode,
        bool $includeRomanization,
    ): SubtitleJob {
        $this->extendProcessingTimeLimit();

        $audio = null;
        $stage = 'preparing';

        try {
            $stage = 'acquiring-audio';
            $this->markJobRunning($job, $stage, 20);
            $this->logger->audioAcquisitionStarted($job);

            $audio = $this->audioSource->acquire(
                youtubeUrl: $payload['youtubeUrl'],
                requestDurationSeconds: $payload['videoDurationSeconds'] ?? null,
            );

            $this->logger->audioAcquisitionCompleted($job, $audio);

            $stage = 'transcribing';
            $this->markJobRunning($job, $stage, 45);
            $this->logger->transcriptionStarted($job);

            $transcript = $this->transcriptionService->transcribe(
                audio: $audio,
                sourceLanguage: $payload['sourceLanguage'],
            );

            $this->logger->transcriptionCompleted($job, $transcript, $audio);

            $this->recordDetectedSourceLanguage($job, $payload['sourceLanguage'], $transcript->language);
            $job = $job->refresh();
            $draftCues = $this->tracks->draftCues($transcript);

            $stage = 'tokenizing';
            $this->markJobRunning($job, $stage, 65);
            $this->logger->tokenizationStarted($job, count($draftCues));

            $enrichment = $this->translationAnalysis->tokenize(
                cues: $draftCues,
                sourceLanguage: $this->effectiveSourceLanguage($job),
            );

            $this->logger->tokenizationCompleted($job, $enrichment);

            if ($includeRomanization && $this->shouldRomanizeTranscript($enrichment->cues)) {
                $stage = 'romanizing';
                $this->markJobRunning($job, $stage, 78);
                $this->logger->romanizationStarted($job, count($enrichment->cues));

                $enrichment = $this->translationAnalysis->romanize(
                    cues: $enrichment->cues,
                    sourceLanguage: $this->effectiveSourceLanguage($job),
                );

                $this->logger->romanizationCompleted($job, $enrichment);
            }

            if ($enrichmentMode === 'full' && ! $this->isSameLanguageGeneration($job)) {
                $stage = 'enriching';
                $this->markJobRunning($job, $stage, 85);
                $this->logger->enrichmentStarted($job, count($enrichment->cues));

                $enrichment = $this->translationAnalysis->enrich(
                    cues: $enrichment->cues,
                    sourceLanguage: $this->effectiveSourceLanguage($job),
                    targetLanguage: $job->target_language,
                    includeRomanization: $includeRomanization,
                );

                $this->logger->enrichmentCompleted($job, $enrichment);
            }

            $stage = 'finalizing';
            $this->markJobRunning($job, $stage, 95);
            $track = $this->tracks->generate($job->refresh(), $transcript, $enrichment);
            $job->update([
                'video_duration_seconds' => $audio->durationSeconds,
                'status' => 'completed',
                'stage' => 'finalizing',
                'progress_percent' => 100,
                'error_code' => null,
                'error_message' => null,
                'expires_at' => $track->expires_at,
            ]);
            $this->logger->trackGenerated($job->refresh(), $track, $audio->durationSeconds);

            return $job->refresh()->load('track');

        } catch (SubtitleProcessingException $exception) {
            $this->markJobFailed($job, $stage, $exception->publicCode, $exception->getMessage());
            $this->logger->processingFailed($job, $stage, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $this->markJobFailed($job, $stage, 'internal_error', 'Generation did not complete.');
            $this->logger->unexpectedFailure($job, $stage, $exception);

            throw $exception;
        } finally {
            if ($audio instanceof TemporaryAudioFile) {
                $audio->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(array $payload, string $installId, ?string $requestIp, string $processingVersion): SubtitleJob
    {
        return SubtitleJob::create([
            'public_id' => (string) Str::uuid(),
            'youtube_video_id' => $payload['youtubeVideoId'],
            'youtube_url' => $payload['youtubeUrl'],
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'source_language' => $payload['sourceLanguage'],
            'detected_source_language' => null,
            'target_language' => $payload['targetLanguage'],
            'processing_version' => $processingVersion,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'install_id' => $installId,
            'request_ip' => $requestIp,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resetJob(SubtitleJob $job, array $payload, string $installId, ?string $requestIp): void
    {
        $job->track()->delete();
        $job->unsetRelation('track');

        $job->update([
            'youtube_url' => $payload['youtubeUrl'],
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'detected_source_language' => null,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'error_code' => null,
            'error_message' => null,
            'install_id' => $installId,
            'request_ip' => $requestIp,
            'expires_at' => null,
        ]);
    }

    private function processingVersion(string $enrichmentMode, bool $includeRomanization): string
    {
        if ($enrichmentMode === 'full') {
            return $includeRomanization
                ? self::PROCESSING_VERSION_FULL_ROMANIZED
                : self::PROCESSING_VERSION_FULL;
        }

        return $includeRomanization
            ? self::PROCESSING_VERSION_ON_DEMAND_ROMANIZED
            : self::PROCESSING_VERSION_ON_DEMAND;
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

    private function recordDetectedSourceLanguage(SubtitleJob $job, string $requestedSourceLanguage, string $transcriptLanguage): void
    {
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

    private function markJobFailed(SubtitleJob $job, string $stage, string $errorCode, string $errorMessage): void
    {
        $job->update([
            'status' => 'failed',
            'stage' => $stage,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }

    private function extendProcessingTimeLimit(): void
    {
        if (! function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit((int) config('subtitles.processing_timeout_seconds', 0));
    }
}
