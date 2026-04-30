<?php

namespace Tests\Feature;

use App\Jobs\ProcessSubtitleJob;
use App\Models\SubtitleJob;
use App\SubtitleJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSubtitleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_processing_job_writes_track_and_completes_job(): void
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
        ]);

        ProcessSubtitleJob::dispatchSync($job);

        $job->refresh();

        $this->assertSame(SubtitleJobStatus::Completed, $job->status);
        $this->assertSame(100, $job->progress_percent);
        $this->assertNotNull($job->expires_at);
        $this->assertDatabaseHas('subtitle_tracks', [
            'subtitle_job_id' => $job->id,
            'youtube_video_id' => 'dQw4w9WgXcQ',
        ]);
    }
}
