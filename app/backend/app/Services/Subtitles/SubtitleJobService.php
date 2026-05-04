<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\OpenAiWebVttTranscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

class SubtitleJobService
{
    public const PROCESSING_VERSION = 'generated-webvtt-sync-v1';

    public function __construct(
        private readonly YouTubeAudioSource $audioSource,
        private readonly OpenAiWebVttTranscriptionService $transcriptionService,
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
            Log::info('backend.track_reused', [
                'job_id' => $job->public_id,
                'track_id' => $job->track->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'processing_version' => self::PROCESSING_VERSION,
            ]);

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
                'provider' => Lab::OpenAI->value,
                'adapter' => 'openai-http',
                'model' => (string) config('ai.providers.'.Lab::OpenAI->value.'.models.transcription.default', 'whisper-1'),
                'response_format' => 'vtt',
            ]);

            $transcript = $this->transcriptionService->transcribe(
                audio: $audio,
                sourceLanguage: $payload['sourceLanguage'],
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
            $this->logGeneratedTrack($job->refresh(), $track, $audio->durationSeconds);

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

    private function logGeneratedTrack(SubtitleJob $job, SubtitleTrack $track, int $audioDurationSeconds): void
    {
        $cues = $track->cues;
        $lastCue = $cues[array_key_last($cues)] ?? null;
        $trackDurationSeconds = is_array($lastCue) ? ((int) ($lastCue['endMs'] ?? 0)) / 1000 : 0.0;
        $durationDeltaSeconds = abs($audioDurationSeconds - $trackDurationSeconds);

        Log::info('backend.track_generation_completed', [
            'job_id' => $job->public_id,
            'track_id' => $track->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'cue_count' => count($cues),
            'track_duration_seconds' => round($trackDurationSeconds, 3),
            'audio_duration_seconds' => $audioDurationSeconds,
            'processing_version' => self::PROCESSING_VERSION,
            'expires_at' => $track->expires_at->toJSON(),
        ]);

        if ($durationDeltaSeconds > max(5, $audioDurationSeconds * 0.05)) {
            Log::warning('backend.track_duration_mismatch', [
                'job_id' => $job->public_id,
                'track_id' => $track->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'track_duration_seconds' => round($trackDurationSeconds, 3),
                'audio_duration_seconds' => $audioDurationSeconds,
                'delta_seconds' => round($durationDeltaSeconds, 3),
            ]);
        }
    }
}
