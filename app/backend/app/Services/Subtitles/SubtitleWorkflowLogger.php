<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Throwable;

class SubtitleWorkflowLogger
{
    public function trackReused(SubtitleJob $job): void
    {
        Log::info('backend.track_reused', [
            'job_id' => $job->public_id,
            'track_id' => $job->track->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => SubtitleJobService::PROCESSING_VERSION,
        ]);
    }

    public function audioAcquisitionStarted(SubtitleJob $job): void
    {
        Log::info('backend.audio_acquisition_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
        ]);
    }

    public function audioAcquisitionCompleted(SubtitleJob $job, TemporaryAudioFile $audio): void
    {
        Log::info('backend.audio_acquisition_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'duration_seconds' => $audio->durationSeconds,
            'audio_bytes' => $audio->sizeBytes,
        ]);
    }

    public function transcriptionStarted(SubtitleJob $job): void
    {
        Log::info('backend.transcription_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'openai-http',
            'model' => (string) config('ai.providers.'.Lab::OpenAI->value.'.models.transcription.default', 'whisper-1'),
            'response_format' => 'vtt',
        ]);
    }

    public function transcriptionCompleted(SubtitleJob $job, TimestampedTranscript $transcript, TemporaryAudioFile $audio): void
    {
        Log::info('backend.transcription_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'segment_count' => count($transcript->segments),
            'duration_seconds' => $transcript->durationSeconds ?? $audio->durationSeconds,
        ]);
    }

    public function enrichmentStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.enrichment_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => (string) config(
                'ai.providers.'.Lab::OpenAI->value.'.models.enrichment.default',
                config('ai.providers.'.Lab::OpenAI->value.'.models.text.default', 'gpt-4o-mini'),
            ),
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'cue_count' => $cueCount,
        ]);
    }

    public function enrichmentCompleted(SubtitleJob $job, CueEnrichmentResult $enrichment): void
    {
        Log::info('backend.enrichment_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => (string) config(
                'ai.providers.'.Lab::OpenAI->value.'.models.enrichment.default',
                config('ai.providers.'.Lab::OpenAI->value.'.models.text.default', 'gpt-4o-mini'),
            ),
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'source_dialect' => $enrichment->sourceDialect,
            'cue_count' => count($enrichment->cues),
            'token_count' => array_sum(array_map(
                fn (array $cue): int => is_array($cue['tokens'] ?? null) ? count($cue['tokens']) : 0,
                $enrichment->cues,
            )),
        ]);
    }

    public function trackGenerated(SubtitleJob $job, SubtitleTrack $track, int $audioDurationSeconds): void
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
            'processing_version' => SubtitleJobService::PROCESSING_VERSION,
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

    public function processingFailed(SubtitleJob $job, string $stage, SubtitleProcessingException $exception): void
    {
        Log::warning("backend.{$stage}_failed", [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'error_code' => $exception->publicCode,
            ...$exception->context,
        ]);
    }

    public function unexpectedFailure(SubtitleJob $job, string $stage, Throwable $exception): void
    {
        Log::error("backend.{$stage}_failed", [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'exception' => $exception::class,
        ]);
    }
}
