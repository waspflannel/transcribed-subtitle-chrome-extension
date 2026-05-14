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
    public function jobCreated(SubtitleJob $job): void
    {
        Log::info('backend.subtitle_job_created', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
        ]);
    }

    public function incompleteJobReused(SubtitleJob $job): void
    {
        Log::info('backend.subtitle_job_reused_for_retry', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
        ]);
    }

    public function trackReused(SubtitleJob $job): void
    {
        Log::info('backend.track_reused', [
            'job_id' => $job->public_id,
            'track_id' => $job->track->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
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
            'provider' => Lab::ElevenLabs->value,
            'adapter' => 'elevenlabs-http',
            'model' => (string) config('ai.providers.'.Lab::ElevenLabs->value.'.models.transcription.default'),
            'timestamps_granularity' => 'word',
        ]);
    }

    public function transcriptionCompleted(SubtitleJob $job, TimestampedTranscript $transcript, TemporaryAudioFile $audio): void
    {
        Log::info('backend.transcription_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'segment_count' => count($transcript->segments),
            'duration_seconds' => $transcript->durationSeconds ?? $audio->durationSeconds,
            'detected_source_language' => $transcript->language,
        ]);
    }

    public function enrichmentStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.enrichment_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => $this->openAiModel('enrichment'),
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'cue_count' => $cueCount,
        ]);
    }

    public function tokenizationStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.tokenization_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => $this->openAiModel('tokenization'),
            'source_language' => $job->source_language,
            'cue_count' => $cueCount,
        ]);
    }

    public function tokenizationCompleted(SubtitleJob $job, CueEnrichmentResult $enrichment): void
    {
        Log::info('backend.tokenization_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => $this->openAiModel('tokenization'),
            'source_language' => $job->source_language,
            'source_dialect' => $enrichment->sourceDialect,
            'cue_count' => count($enrichment->cues),
            'token_count' => $this->tokenCount($enrichment),
        ]);
    }

    public function enrichmentCompleted(SubtitleJob $job, CueEnrichmentResult $enrichment): void
    {
        Log::info('backend.enrichment_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => $this->openAiModel('enrichment'),
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'source_dialect' => $enrichment->sourceDialect,
            'cue_count' => count($enrichment->cues),
            'token_count' => $this->tokenCount($enrichment),
        ]);
    }

    public function romanizationStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.romanization_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => $this->openAiModel('romanization'),
            'cue_count' => $cueCount,
        ]);
    }

    public function romanizationCompleted(SubtitleJob $job, CueEnrichmentResult $enrichment): void
    {
        Log::info('backend.romanization_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => Lab::OpenAI->value,
            'adapter' => 'laravel-ai-sdk',
            'model' => $this->openAiModel('romanization'),
            'cue_count' => count($enrichment->cues),
        ]);
    }

    public function trackGenerated(SubtitleJob $job, SubtitleTrack $track, int $audioDurationSeconds): void
    {
        $cues = $track->cues;
        $lastCue = $cues[array_key_last($cues)];
        $trackDurationSeconds = ((int) $lastCue['endMs']) / 1000;
        $trackOverrunSeconds = $trackDurationSeconds - $audioDurationSeconds;

        Log::info('backend.track_generation_completed', [
            'job_id' => $job->public_id,
            'track_id' => $track->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'cue_count' => count($cues),
            'track_duration_seconds' => round($trackDurationSeconds, 3),
            'audio_duration_seconds' => $audioDurationSeconds,
            'processing_version' => $job->processing_version,
            'expires_at' => $track->expires_at->toJSON(),
        ]);

        if ($trackOverrunSeconds > 5) {
            Log::info('backend.track_duration_overrun', [
                'job_id' => $job->public_id,
                'track_id' => $track->public_id,
                'youtube_video_id' => $job->youtube_video_id,
                'track_duration_seconds' => round($trackDurationSeconds, 3),
                'audio_duration_seconds' => $audioDurationSeconds,
                'delta_seconds' => round($trackOverrunSeconds, 3),
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

    private function openAiModel(string $purpose): string
    {
        return (string) config('ai.providers.'.Lab::OpenAI->value.'.models.'.$purpose.'.default');
    }

    private function tokenCount(CueEnrichmentResult $enrichment): int
    {
        return array_sum(array_map(
            fn (array $cue): int => count($cue['tokens']),
            $enrichment->cues,
        ));
    }
}
