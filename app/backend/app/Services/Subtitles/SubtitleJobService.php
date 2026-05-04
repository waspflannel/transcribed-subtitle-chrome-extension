<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\OpenAiWebVttTranscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SubtitleJobService
{
    public const PROCESSING_VERSION = 'generated-webvtt-sync-v1';

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly OpenAiWebVttTranscriptionService $transcriptionService,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
        private readonly SubtitleWorkflowLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        $job = DB::transaction(function () use ($payload, $installId, $requestIp): SubtitleJob {
            $job = SubtitleJob::query()
                ->with('track')
                ->where('youtube_video_id', $payload['youtubeVideoId'])
                ->where('source_language', $payload['sourceLanguage'])
                ->where('target_language', $payload['targetLanguage'])
                ->where('processing_version', self::PROCESSING_VERSION)
                ->first();

            if ($job) {
                if ($this->hasReadyTrack($job)) {
                    return $job;
                }

                $this->resetJob($job, $payload, $installId, $requestIp);
            } else {
                $job = $this->createJob($payload, $installId, $requestIp);
            }

            return $job->refresh();
        });

        if ($this->hasReadyTrack($job->load('track'))) {
            $this->logger->trackReused($job);

            return $job;
        }

        return $this->generateTrack($job, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function generateTrack(SubtitleJob $job, array $payload): SubtitleJob
    {
        $audio = null;
        $stage = 'audio_acquisition';

        try {
            $this->logger->audioAcquisitionStarted($job);

            $audio = $this->audioSource->acquire(
                videoId: $payload['youtubeVideoId'],
                youtubeUrl: $payload['youtubeUrl'] ?? null,
                requestDurationSeconds: $payload['videoDurationSeconds'] ?? null,
            );

            $this->logger->audioAcquisitionCompleted($job, $audio);

            $stage = 'transcription';

            $this->logger->transcriptionStarted($job);

            $transcript = $this->transcriptionService->transcribe(
                audio: $audio,
                sourceLanguage: $payload['sourceLanguage'],
            );

            $this->logger->transcriptionCompleted($job, $transcript, $audio);

            $stage = 'track_generation';
            $track = $this->tracks->generate($job->refresh(), $transcript);
            $job->update([
                'video_duration_seconds' => $audio->durationSeconds,
                'expires_at' => $track->expires_at,
            ]);
            $this->logger->trackGenerated($job->refresh(), $track, $audio->durationSeconds);

            return $job->refresh()->load('track');
        } catch (SubtitleProcessingException $exception) {
            $this->logger->processingFailed($job, $stage, $exception);

            throw $exception;
        } catch (Throwable $exception) {
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
    private function createJob(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        return SubtitleJob::create([
            'public_id' => (string) Str::uuid(),
            'youtube_video_id' => $payload['youtubeVideoId'],
            'youtube_url' => $payload['youtubeUrl'] ?? null,
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'source_language' => $payload['sourceLanguage'],
            'target_language' => $payload['targetLanguage'],
            'processing_version' => self::PROCESSING_VERSION,
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
            'install_id' => $installId,
            'request_ip' => $requestIp,
            'expires_at' => null,
        ]);
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }
}
