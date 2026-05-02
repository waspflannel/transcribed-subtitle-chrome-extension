<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\LaravelAiTranscriptionService;
use App\Services\Transcription\TranscriptionOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SubtitleJobService
{
    public const PROCESSING_VERSION = 'audio-transcription-proof-v1';

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly LaravelAiTranscriptionService $transcriptionService,
        private readonly TimestampedSubtitleTrackGenerator $tracks,
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
            Log::info('backend.audio_acquisition_started', [
                'job_id' => $job->public_id,
                'youtube_video_id' => $job->youtube_video_id,
            ]);

            $audio = $this->audioSource->acquire(
                videoId: $payload['youtubeVideoId'],
                youtubeUrl: $payload['youtubeUrl'] ?? null,
                requestDurationSeconds: $payload['videoDurationSeconds'] ?? null,
            );

            Log::info('backend.audio_acquisition_completed', [
                'job_id' => $job->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'duration_seconds' => $audio->durationSeconds,
                'audio_bytes' => $audio->sizeBytes,
            ]);

            $stage = 'transcription';

            Log::info('backend.transcription_started', [
                'job_id' => $job->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'provider' => 'openai',
                'sdk' => 'laravel-ai',
            ]);

            $transcript = $this->transcriptionService->transcribe(
                audio: $audio,
                options: new TranscriptionOptions($payload['sourceLanguage']),
            );

            Log::info('backend.transcription_completed', [
                'job_id' => $job->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'segment_count' => count($transcript->segments),
                'duration_seconds' => $transcript->durationSeconds ?? $audio->durationSeconds,
            ]);

            $stage = 'track_generation';
            $track = $this->tracks->generate($job->refresh(), $transcript);
            $job->update([
                'video_duration_seconds' => $audio->durationSeconds,
                'expires_at' => $track->expires_at,
            ]);

            return $job->refresh()->load('track');
        } catch (SubtitleProcessingException $exception) {
            Log::warning("backend.{$stage}_failed", [
                'job_id' => $job->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'error_code' => $exception->publicCode,
                ...$exception->context,
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error("backend.{$stage}_failed", [
                'job_id' => $job->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'exception' => $exception::class,
            ]);

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
            'options' => $payload['options'],
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
            'options' => $payload['options'],
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
