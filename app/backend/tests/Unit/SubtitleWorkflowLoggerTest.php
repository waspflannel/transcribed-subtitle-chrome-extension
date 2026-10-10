<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleWorkflowLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SubtitleWorkflowLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_generated_logs_completion_without_warning_on_trailing_silence(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'startMs' => 0,
                        'endMs' => 2000,
                        'sourceText' => 'short track',
                        'translatedText' => 'short track',
                        'tokens' => [
                            ['index' => 0, 'text' => 'short', 'normalizedText' => 'short'],
                        ],
                    ],
                ],
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('backend.track_generation_completed', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['track_id'] === $track->public_id
                    && $context['cue_count'] === 1
                    && $context['track_duration_seconds'] === 2.0
                    && $context['audio_duration_seconds'] === 100,
            ));

        Log::shouldReceive('warning')->never();

        $this->logger()->trackGenerated($job, $track, 100);
    }

    public function test_track_generated_logs_info_when_track_overruns_audio(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'startMs' => 0,
                        'endMs' => 12000,
                        'sourceText' => 'overrun track',
                        'translatedText' => 'overrun track',
                        'tokens' => [
                            ['index' => 0, 'text' => 'overrun', 'normalizedText' => 'overrun'],
                        ],
                    ],
                ],
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('backend.track_generation_completed', Mockery::type('array'));

        Log::shouldReceive('info')
            ->once()
            ->with('backend.track_duration_overrun', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['track_id'] === $track->public_id
                    && $context['delta_seconds'] === 10.0,
            ));

        $this->logger()->trackGenerated($job, $track, 2);
    }

    public function test_processing_failures_keep_stable_error_context(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('backend.transcription_failed', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['youtube_video_id'] === 'dQw4w9WgXcQ'
                    && $context['error_code'] === 'transcription_failed'
                    && $context['cue_index'] === 12
                    && $context['token_position'] === 3
                    && $context['reason'] === 'provider_error',
            ));

        $this->logger()->processingFailed(
            $job,
            'transcription',
            SubtitleProcessingException::transcriptionFailed(context: ['reason' => 'provider_error', 'cue_index' => 12, 'token_position' => 3]),
        );
    }

    public function test_processing_failures_drop_unsafe_context_and_keep_safe_scalars(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->with('backend.audio_acquisition_failed', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['youtube_video_id'] === 'dQw4w9WgXcQ'
                    && $context['error_code'] === 'audio_acquisition_failed'
                    && $context['reason'] === 'process_failed'
                    && $context['provider'] === 'yt-dlp'
                    && $context['adapter'] === 'process'
                    && $context['model_key'] === 'audio.source'
                    && $context['status'] === 'failed'
                    && $context['queue'] === 'subtitle-generation-base'
                    && $context['queue_family'] === 'generation'
                    && $context['queue_connection'] === 'redis'
                    && $context['duration_seconds'] === 42
                    && $context['max_duration_seconds'] === 3600
                    && $context['batch_index'] === 2
                    && $context['attempt'] === 1
                    && ! array_key_exists('command', $context)
                    && ! array_key_exists('stdout_excerpt', $context)
                    && ! array_key_exists('stderr_excerpt', $context)
                    && ! array_key_exists('audio_path', $context)
                    && ! array_key_exists('install_id', $context)
                    && ! array_key_exists('prompt', $context)
                    && ! array_key_exists('transcript', $context)
                    && ! array_key_exists('provider_payload', $context)
                    && ! array_key_exists('tokens', $context)
                    && ! array_key_exists('details', $context),
            ));

        $this->logger()->processingFailed(
            $job,
            'audio_acquisition',
            SubtitleProcessingException::audioAcquisitionFailed(context: [
                'reason' => 'process_failed',
                'provider' => 'yt-dlp',
                'adapter' => 'process',
                'model_key' => 'audio.source',
                'status' => 'failed',
                'queue' => 'subtitle-generation-base',
                'queue_family' => 'generation',
                'queue_connection' => 'redis',
                'duration_seconds' => 42,
                'max_duration_seconds' => 3600,
                'batch_index' => 2,
                'attempt' => 1,
                'command' => 'yt-dlp https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'stdout_excerpt' => 'raw provider output',
                'stderr_excerpt' => 'raw provider error',
                'audio_path' => storage_path('framework/testing/audio/private.m4a'),
                'install_id' => 'install_'.str_repeat('a', 32),
                'prompt' => 'translate this transcript',
                'transcript' => 'full transcript text',
                'provider_payload' => ['raw' => true],
                'tokens' => [['text' => 'secret']],
                'details' => ['nested' => 'data'],
            ]),
        );
    }

    public function test_queue_and_stage_timing_logs_stay_sanitized(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'processing_version' => 'scribe-v2-tokenizer-v8-async-on-demand',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('backend.subtitle_queue_wait_observed', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['youtube_video_id'] === 'dQw4w9WgXcQ'
                    && $context['stage'] === 'tokenizing'
                    && $context['wait_ms'] === 25
                    && $context['batch_index'] === 0
                    && ! array_key_exists('prompt', $context)
                    && ! array_key_exists('transcript', $context)
                    && ! array_key_exists('tokens', $context),
            ));

        Log::shouldReceive('info')
            ->once()
            ->with('backend.subtitle_stage_timing', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['stage'] === 'tokenizing'
                    && $context['duration_ms'] === 100
                    && $context['batch_index'] === 0
                    && ! array_key_exists('translation', $context)
                    && ! array_key_exists('romanization', $context),
            ));

        Log::shouldReceive('info')
            ->once()
            ->with('backend.subtitle_completed_track_timing', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['youtube_video_id'] === 'dQw4w9WgXcQ'
                    && $context['duration_ms'] === 500
                    && $context['processing_version'] === 'scribe-v2-tokenizer-v8-async-on-demand'
                    && ! array_key_exists('sourceText', $context)
                    && ! array_key_exists('translatedText', $context),
            ));

        $this->logger()->queueWaitObserved($job, 'tokenizing', 25, 0);
        $this->logger()->stageTiming($job, 'tokenizing', 100, 0);
        $this->logger()->completedTrackTiming($job, 500);
    }

    public function test_enrichment_logs_name_the_claude_cli_adapter(): void
    {
        $job = SubtitleJob::factory()->create(['ai_provider' => 'claude', 'ai_model' => 'sonnet']);

        Log::shouldReceive('info')
            ->once()
            ->with('backend.translation_started', Mockery::on(
                fn (array $context): bool => $context['provider'] === 'claude'
                    && $context['adapter'] === 'claude-code-cli'
                    && $context['model'] === 'sonnet'
                    && ! array_key_exists('fast_mode', $context),
            ));

        $this->logger()->translationStarted($job, 3);
    }

    private function logger(): SubtitleWorkflowLogger
    {
        return new SubtitleWorkflowLogger;
    }
}
