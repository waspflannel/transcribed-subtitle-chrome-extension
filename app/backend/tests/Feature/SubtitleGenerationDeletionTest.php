<?php

namespace Tests\Feature;

use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Models\User;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleJobService;
use App\Support\ExtensionTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SubtitleGenerationDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_saved_generations_including_the_last_without_refunding_usage(): void
    {
        $user = User::factory()->create();
        $this->withExtensionAuth('install_'.str_repeat('a', 32), $user);
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $otherJob = SubtitleJob::factory()->for($user)->create([
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
        $ledger = app(UsageLedger::class);
        $period = $ledger->periodForUser($user);
        $ledger->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 4);
        $ledger->debitCompletedJob($job, $track);
        $before = $ledger->summary($user, $period['start'], $period['end']);
        $usageEvents = BillingUsageEvent::query()->where('subtitle_job_id', $job->id)->get();
        $this->assertSame(4, $before['used']);

        $this->deleteJson('/v1/subtitle-generations/'.$job->public_id)->assertOk()->assertExactJson(['ok' => true]);

        foreach ([$job, $track, $artifact, $event, $correction] as $deleted) {
            $this->assertModelMissing($deleted);
        }
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertModelExists($otherJob);
        $this->assertModelExists($otherTrack);
        $this->assertSame($before, $ledger->summary($user, $period['start'], $period['end']));
        foreach ($usageEvents as $usageEvent) {
            $this->assertModelExists($usageEvent);
            $this->assertNull($usageEvent->fresh()->subtitle_job_id);
            $this->assertNull($usageEvent->fresh()->subtitle_track_id);
        }
        $this->getJson('/v1/subtitle-jobs/'.$job->public_id)->assertNotFound();
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId='.$job->youtube_video_id)
            ->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.jobId', $otherJob->public_id);

        $this->deleteJson('/v1/subtitle-generations/'.$otherJob->public_id)->assertOk()->assertExactJson(['ok' => true]);
        $this->getJson('/v1/subtitle-jobs?youtubeVideoId='.$job->youtube_video_id)->assertOk()->assertJsonCount(0, 'jobs');
        $this->getJson('/v1/subtitle-jobs')->assertOk()->assertJsonCount(0, 'jobs');
        $this->getJson('/v1/subtitle-jobs/'.$otherJob->public_id)->assertNotFound();
    }

    public function test_generation_deletion_requires_authentication_and_subtitles_write_ability(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $this->withHeader('X-Extension-Install-Id', 'install_'.str_repeat('a', 32))
            ->deleteJson('/v1/subtitle-generations/'.$job->public_id)->assertUnauthorized();

        $token = $job->user->createToken('account-only', [ExtensionTokenAbility::ACCOUNT_READ]);
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->deleteJson('/v1/subtitle-generations/'.$job->public_id)->assertForbidden();
        $this->assertModelExists($job);
    }

    public function test_generation_deletion_rejects_foreign_and_missing_jobs(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $this->withExtensionAuth('install_'.str_repeat('a', 32), User::factory()->create());

        foreach ([$job->public_id, (string) Str::uuid()] as $jobId) {
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
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create(['status' => $status]);
        $this->withExtensionAuth('install_'.str_repeat('a', 32), $user);

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_website_deletion_removes_generations_from_extension_reads(bool $clearAll): void
    {
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'completed']);
        SubtitleTrack::factory()->for($job, 'job')->create();
        $url = $clearAll
            ? route('dashboard.jobs.clear')
            : route('dashboard.jobs.destroy', ['jobId' => $job->public_id]);

        $this->actingAs($user)->delete($url)->assertRedirect(route('dashboard'));
        $this->withExtensionAuth('install_'.str_repeat('a', 32), $user);

        $this->getJson('/v1/subtitle-jobs?youtubeVideoId='.$job->youtube_video_id)->assertOk()->assertJsonCount(0, 'jobs');
        $this->getJson('/v1/subtitle-jobs/'.$job->public_id)->assertNotFound();
    }
}
