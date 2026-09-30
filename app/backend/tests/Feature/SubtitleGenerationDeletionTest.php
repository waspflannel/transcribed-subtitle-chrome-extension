<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubtitleGenerationDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_instance_can_delete_saved_generations_including_the_last(): void
    {
        $this->withExtensionInstall('install_'.str_repeat('a', 32));
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $otherJob = SubtitleJob::factory()->create([
            'status' => 'completed',
            'youtube_video_id' => $job->youtube_video_id,
            'ai_provider' => 'cerebras',
            'ai_model' => 'gpt-oss-120b',
        ]);
        $otherTrack = SubtitleTrack::factory()->for($otherJob, 'job')->create();
        $artifact = SubtitleJobArtifact::create([
            'subtitle_job_id' => $job->id,
            'artifact_type' => 'transcript',
            'batch_index' => 0,
            'payload' => ['sample' => 'payload'],
        ]);
        $event = SubtitleJobEvent::create([
            'subtitle_job_id' => $job->id,
            'public_job_id' => $job->public_id,
            'run_id' => $job->run_id,
            'event' => 'job.created',
        ]);
        $correction = SubtitleTrackLyricsCorrection::create([
            'subtitle_track_id' => $track->id,
            'attempt_id' => (string) Str::uuid(),
            'status' => 'running',
            'lyrics' => 'Private correction lyrics',
            'work_state' => ['private' => 'correction state'],
        ]);
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.'chunk.flac', 'temporary audio');

        $this->deleteJson('/v1/subtitle-generations/'.$job->public_id)->assertOk()->assertExactJson(['ok' => true]);

        foreach ([$job, $track, $artifact, $event, $correction] as $deleted) {
            $this->assertModelMissing($deleted);
        }
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertModelExists($otherJob);
        $this->assertModelExists($otherTrack);
        $this->getJson('/v1/subtitle-jobs/'.$job->public_id)->assertNotFound();
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId='.$job->youtube_video_id)
            ->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.jobId', $otherJob->public_id);

        $this->deleteJson('/v1/subtitle-generations/'.$otherJob->public_id)->assertOk()->assertExactJson(['ok' => true]);
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId='.$job->youtube_video_id)->assertOk()->assertJsonCount(0, 'jobs');
        $this->getJson('/v1/subtitle-jobs')->assertOk()->assertJsonCount(0, 'jobs');
        $this->getJson('/v1/subtitle-jobs/'.$otherJob->public_id)->assertNotFound();
    }

    public function test_generation_deletion_rejects_missing_jobs(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $this->withExtensionInstall('install_'.str_repeat('a', 32));

        foreach ([(string) Str::uuid()] as $jobId) {
            $this->deleteJson('/v1/subtitle-generations/'.$jobId)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
        $this->assertModelExists($job);
    }

    #[TestWith(['queued'])]
    #[TestWith(['running'])]
    #[TestWith(['failed'])]
    #[TestWith(['cancelled'])]
    public function test_generation_deletion_does_not_cancel_or_delete_noncompleted_jobs(string $status): void
    {
        $job = SubtitleJob::factory()->create(['status' => $status]);
        $this->withExtensionInstall('install_'.str_repeat('a', 32));

        $this->deleteJson('/v1/subtitle-generations/'.$job->public_id)->assertNotFound();

        $this->assertSame($status, $job->fresh()->status);
    }

    public function test_generation_deletion_rechecks_completed_status_under_the_job_lock(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        SubtitleJob::query()->whereKey($job->id)->update(['status' => 'running']);

        $this->assertFalse(app(SubtitleJobService::class)->delete($job, completedOnly: true));
        $this->assertSame('running', $job->fresh()->status);
    }
}
