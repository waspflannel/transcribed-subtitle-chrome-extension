<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

class SubtitleWorkflowLogger
{
    private const SAFE_FAILURE_CONTEXT_KEYS = [
        'adapter',
        'attempt',
        'batch_index',
        'cue_index',
        'duration_seconds',
        'max_duration_seconds',
        'model',
        'model_key',
        'provider',
        'queue',
        'queue_connection',
        'queue_family',
        'reason',
        'status',
        'token_position',
    ];

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
            'ingestion_mode' => $job->transcription_ingestion_mode,
        ]);
    }

    public function transcriptionCompleted(SubtitleJob $job, TimestampedTranscript $transcript, ?int $audioDurationSeconds): void
    {
        Log::info('backend.transcription_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'segment_count' => count($transcript->segments),
            'duration_seconds' => $transcript->durationSeconds ?? $audioDurationSeconds,
            'detected_source_language' => $transcript->language,
        ]);
    }

    public function tokenizationStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.tokenization_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => $job->ai_provider,
            'adapter' => $job->ai_provider === 'codex' ? 'codex-app-server' : 'laravel-ai-sdk',
            'model' => $job->ai_model,
            ...($job->ai_provider === 'codex' ? ['fast_mode' => (bool) $job->ai_fast_mode] : []),
            'source_language' => $job->source_language,
            'cue_count' => $cueCount,
        ]);
    }

    public function tokenizationCompleted(SubtitleJob $job, CueEnrichmentResult $enrichment): void
    {
        Log::info('backend.tokenization_completed', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => $job->ai_provider,
            'adapter' => $job->ai_provider === 'codex' ? 'codex-app-server' : 'laravel-ai-sdk',
            'model' => $job->ai_model,
            ...($job->ai_provider === 'codex' ? ['fast_mode' => (bool) $job->ai_fast_mode] : []),
            'source_language' => $job->source_language,
            'cue_count' => count($enrichment->cues),
            'token_count' => $this->tokenCount($enrichment),
        ]);
    }

    public function romanizationStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.romanization_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => $job->ai_provider,
            'adapter' => $job->ai_provider === 'codex' ? 'codex-app-server' : 'laravel-ai-sdk',
            'model' => $job->ai_model,
            ...($job->ai_provider === 'codex' ? ['fast_mode' => (bool) $job->ai_fast_mode] : []),
            'cue_count' => $cueCount,
        ]);
    }

    public function translationStarted(SubtitleJob $job, int $cueCount): void
    {
        Log::info('backend.translation_started', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'provider' => $job->ai_provider,
            'adapter' => $job->ai_provider === 'codex' ? 'codex-app-server' : 'laravel-ai-sdk',
            'model' => $job->ai_model,
            ...($job->ai_provider === 'codex' ? ['fast_mode' => (bool) $job->ai_fast_mode] : []),
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'cue_count' => $cueCount,
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
            'expires_at' => $track->expires_at?->toJSON(),
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

    public function queueWaitObserved(SubtitleJob $job, string $stage, int $waitMs, ?int $batchIndex = null): void
    {
        Log::info('backend.subtitle_queue_wait_observed', $this->withOptionalBatchIndex([
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'stage' => $stage,
            'queue_connection' => config('subtitles.queue.connection'),
            'queue_family' => $batchIndex === null ? SubtitleQueue::FAMILY_GENERATION : SubtitleQueue::FAMILY_BATCH,
            'queue' => $batchIndex === null ? SubtitleQueue::generationNameForJob($job) : SubtitleQueue::batchNameForJob($job),
            'wait_ms' => $waitMs,
        ], $batchIndex));
    }

    public function stageTiming(SubtitleJob $job, string $stage, int $durationMs, ?int $batchIndex = null): void
    {
        Log::info('backend.subtitle_stage_timing', $this->withOptionalBatchIndex([
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'stage' => $stage,
            'duration_ms' => $durationMs,
        ], $batchIndex));
    }

    public function completedTrackTiming(SubtitleJob $job, int $durationMs): void
    {
        Log::info('backend.subtitle_completed_track_timing', [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'duration_ms' => $durationMs,
            'processing_version' => $job->processing_version,
            'estimated_provider_cost_microusd' => $job->estimated_provider_cost_microusd,
        ]);
    }

    public function processingFailed(SubtitleJob $job, string $stage, SubtitleProcessingException $exception): void
    {
        Log::warning("backend.{$stage}_failed", [
            'job_id' => $job->public_id,
            'youtube_video_id' => $job->youtube_video_id,
            'error_code' => $exception->publicCode,
            ...$this->sanitizedFailureContext($exception->context),
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

    private function tokenCount(CueEnrichmentResult $enrichment): int
    {
        return array_sum(array_map(
            fn (array $cue): int => count($cue['tokens']),
            $enrichment->cues,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withOptionalBatchIndex(array $context, ?int $batchIndex): array
    {
        if ($batchIndex === null) {
            return $context;
        }

        return [
            ...$context,
            'batch_index' => $batchIndex,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int|float|bool|string>
     */
    public function sanitizedFailureContext(array $context): array
    {
        $safeContext = [];

        foreach (self::SAFE_FAILURE_CONTEXT_KEYS as $key) {
            if (! array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];

            if (is_int($value) || is_float($value) || is_bool($value)) {
                $safeContext[$key] = $value;

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $safeContext[$key] = Str::limit(trim($value), 240, '...');
            }
        }

        return $safeContext;
    }
}
