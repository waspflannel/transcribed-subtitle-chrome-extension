<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleWorkflowLogger;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
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

    public function test_enrichment_logs_progress_without_generated_text(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);

        Log::shouldReceive('info')
            ->once()
            ->with('backend.enrichment_started', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['youtube_video_id'] === 'dQw4w9WgXcQ'
                    && $context['provider'] === 'openai'
                    && $context['adapter'] === 'laravel-ai-sdk'
                    && $context['cue_count'] === 1
                    && ! array_key_exists('prompt', $context),
            ));

        Log::shouldReceive('info')
            ->once()
            ->with('backend.enrichment_completed', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['source_dialect'] === 'unknown'
                    && $context['cue_count'] === 1
                    && $context['token_count'] === 1
                    && ! array_key_exists('translated_text', $context),
            ));

        $this->logger()->enrichmentStarted($job, 1);
        $this->logger()->enrichmentCompleted($job, new CueEnrichmentResult([
            [
                'cueId' => 'cue-0001',
                'index' => 0,
                'startMs' => 0,
                'endMs' => 1000,
                'sourceText' => 'source',
                'translatedText' => 'translation',
                'tokens' => [
                    ['index' => 0, 'text' => 'source', 'normalizedText' => 'source'],
                ],
            ],
        ], 'unknown'));
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
                    && $context['reason'] === 'provider_error',
            ));

        $this->logger()->processingFailed(
            $job,
            'transcription',
            SubtitleProcessingException::transcriptionFailed(context: ['reason' => 'provider_error']),
        );
    }

    private function logger(): SubtitleWorkflowLogger
    {
        return new SubtitleWorkflowLogger;
    }
}
