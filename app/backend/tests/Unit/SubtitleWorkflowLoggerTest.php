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

    public function test_track_generated_logs_completion_and_duration_mismatch(): void
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
                        'tokens' => [],
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

        Log::shouldReceive('warning')
            ->once()
            ->with('backend.track_duration_mismatch', Mockery::on(
                fn (array $context): bool => $context['job_id'] === $job->public_id
                    && $context['track_id'] === $track->public_id
                    && $context['delta_seconds'] === 98.0,
            ));

        $this->logger()->trackGenerated($job, $track, 100);
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
