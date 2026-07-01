<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
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

        // Simulate a reset that hands the job a new run_id (the normal flow
        // also deletes artifacts, but the audit's concern is the check-then-write
        // race when a stale batch survives the reset).
        $job->forceFill(['run_id' => $newRun])->save();

        $this->expectException(SubtitleProcessingException::class);
        $store->cueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES);
    }

    public function test_batch_writes_carry_the_current_run_id_and_reads_stay_scoped(): void
    {
        $store = app(SubtitleJobArtifactStore::class);
        $run = (string) Str::uuid();

        $job = SubtitleJob::factory()->create(['run_id' => $run, 'status' => 'running']);

        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->draftCues());

        $this->assertSame(1, $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES));

        // A stale batch job that survived the reset writes under its old run_id;
        // the unique key prevents it overwriting the current run's row, and reads
        // scoped to the new run_id ignore it entirely.
        $staleRun = (string) Str::uuid();
        $job->forceFill(['run_id' => $staleRun])->save();

        $store->putCueBatchResult($job, SubtitleJobArtifactStore::TOKENIZED_CUES, 0, new CueEnrichmentResult($this->draftCues(), 'unknown'));

        // Back on the current run, the tokenized artifact is not visible.
        $job->forceFill(['run_id' => $run])->save();

        try {
            $store->cueBatchResult($job, SubtitleJobArtifactStore::TOKENIZED_CUES, 0);
            $this->fail('Expected the new run to exclude the stale tokenized artifact.');
        } catch (SubtitleProcessingException) {
            // expected — the stale-run write is invisible to the new run
        }
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
