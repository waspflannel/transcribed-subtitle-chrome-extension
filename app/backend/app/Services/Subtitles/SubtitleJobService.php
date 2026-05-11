<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\TranscriptionService;
use App\Services\TranslationAnalysis\TranslationAnalysisProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SubtitleJobService
{
    public const PROCESSING_VERSION_ON_DEMAND = 'elevenlabs-scribe-v2-transcript-first-romanized-v1';

    public const PROCESSING_VERSION_FULL = 'elevenlabs-scribe-v2-full-enriched-v1';

    public const COMPATIBLE_PROCESSING_VERSIONS = [
        self::PROCESSING_VERSION_ON_DEMAND,
        self::PROCESSING_VERSION_FULL,
    ];

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly TranscriptionService $transcriptionService,
        private readonly TranslationAnalysisProvider $translationAnalysis,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
        private readonly SubtitleWorkflowLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        $enrichmentMode = $this->enrichmentMode($payload);
        $processingVersion = $this->processingVersion($enrichmentMode);

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

        return $this->generateTrack($job, $payload, $enrichmentMode);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function generateTrack(SubtitleJob $job, array $payload, string $enrichmentMode): SubtitleJob
    {
        $this->extendProcessingTimeLimit();

        $audio = null;
        $stage = 'preparing';

        try {
            $stage = 'acquiring-audio';
            $this->markJobRunning($job, $stage, 20);
            $this->logger->audioAcquisitionStarted($job);

            $audio = $this->audioSource->acquire(
                videoId: $payload['youtubeVideoId'],
                youtubeUrl: $payload['youtubeUrl'] ?? null,
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

            $draftCues = $this->tracks->draftCues($transcript);

            if ($enrichmentMode === 'full') {
                $stage = 'enriching';
                $this->markJobRunning($job, $stage, 75);
                $this->logger->enrichmentStarted($job, count($draftCues));

                $enrichment = $this->translationAnalysis->enrich(
                    cues: $draftCues,
                    sourceLanguage: $job->source_language,
                    targetLanguage: $job->target_language,
                );

                $this->logger->enrichmentCompleted($job, $enrichment);
            } else {
                $enrichment = $this->tracks->transcriptOnlyEnrichment($draftCues);

                if ($this->shouldRomanizeTranscript($enrichment->cues)) {
                    $stage = 'romanizing';
                    $this->markJobRunning($job, $stage, 82);
                    $this->logger->romanizationStarted($job, count($enrichment->cues));

                    try {
                        $enrichment = $this->translationAnalysis->romanize(
                            cues: $enrichment->cues,
                            sourceLanguage: 'ar',
                        );

                        $this->logger->romanizationCompleted($job, $enrichment);
                    } catch (SubtitleProcessingException $exception) {
                        $this->logger->romanizationSkipped($job, $exception);
                    }
                }
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
            'youtube_url' => $payload['youtubeUrl'] ?? null,
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'source_language' => $payload['sourceLanguage'],
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
            'youtube_url' => $payload['youtubeUrl'] ?? null,
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enrichmentMode(array $payload): string
    {
        return ($payload['enrichmentMode'] ?? 'on_demand') === 'full' ? 'full' : 'on_demand';
    }

    private function processingVersion(string $enrichmentMode): string
    {
        return $enrichmentMode === 'full'
            ? self::PROCESSING_VERSION_FULL
            : self::PROCESSING_VERSION_ON_DEMAND;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    private function shouldRomanizeTranscript(array $cues): bool
    {
        foreach ($cues as $cue) {
            if (is_string($cue['sourceText'] ?? null) && preg_match('/\p{Arabic}/u', $cue['sourceText']) === 1) {
                return true;
            }
        }

        return false;
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
