<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleJobArtifactRunScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_from_a_previous_run_are_excluded_after_reset(): void
    {
        $store = app(SubtitleJobArtifactStore::class);
        $oldRun = (string) Str::uuid();
        $newRun = (string) Str::uuid();
        $job = SubtitleJob::factory()->create(['run_id' => $oldRun, 'status' => 'running']);

        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());
        $job->forceFill(['run_id' => $newRun])->save();

        $this->expectException(SubtitleProcessingException::class);
        $store->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES);
    }

    public function test_old_cleanup_preserves_replacement_artifacts(): void
    {
        $store = app(SubtitleJobArtifactStore::class);
        $job = SubtitleJob::factory()->create(['status' => 'running']);
        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());
        $old = clone $job;
        $job->update(['run_id' => (string) Str::uuid()]);
        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());

        $store->deleteForJob($old);

        $this->assertDatabaseMissing('subtitle_job_artifacts', ['subtitle_job_id' => $job->id, 'run_id' => $old->run_id]);
        $this->assertSame($this->draftCues(), $store->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES)->cues);
    }

    public function test_stale_run_batch_writes_are_rejected(): void
    {
        $store = app(SubtitleJobArtifactStore::class);
        $run = (string) Str::uuid();
        $job = SubtitleJob::factory()->create(['run_id' => $run, 'status' => 'running']);

        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());
        $this->assertSame(1, $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES));

        $staleJob = clone $job;
        $job->forceFill(['run_id' => (string) Str::uuid()])->save();
        $store->putCueBatchResult(
            $staleJob,
            SubtitleJobArtifactStore::ANALYZED_CUES,
            0,
            new CueEnrichmentResult($this->draftCues()),
        );

        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $job->id,
            'artifact_type' => SubtitleJobArtifactStore::ANALYZED_CUES,
        ]);
    }

    public function test_artifact_writes_are_rejected_after_terminal_state_track_completion_or_deletion(): void
    {
        $store = app(SubtitleJobArtifactStore::class);

        foreach (['failed', 'completed'] as $status) {
            $job = SubtitleJob::factory()->create(['status' => $status]);
            $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());

            $this->assertDatabaseMissing('subtitle_job_artifacts', [
                'subtitle_job_id' => $job->id,
            ]);
        }

        $jobWithTrack = SubtitleJob::factory()->create(['status' => 'running']);
        SubtitleTrack::factory()->for($jobWithTrack, 'job')->create(['expires_at' => now()->addDay()]);
        $store->putCueCollection($jobWithTrack, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());

        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $jobWithTrack->id,
        ]);

        $deletedJob = SubtitleJob::factory()->create(['status' => 'running']);
        $deletedJob->delete();
        $store->putCueCollection($deletedJob, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());

        $this->assertDatabaseMissing('subtitle_job_artifacts', [
            'subtitle_job_id' => $deletedJob->id,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function draftCues(): array
    {
        return [
            [
                'cueId' => 'cue-0001',
                'index' => 0,
                'startMs' => 0,
                'endMs' => 1000,
                'sourceText' => 'hello world',
                'translatedText' => '',
                'tokens' => [],
            ],
        ];
    }
}
