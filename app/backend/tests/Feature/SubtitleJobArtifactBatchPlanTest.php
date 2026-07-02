<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleJobArtifactBatchPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_cues_are_packed_by_character_budget(): void
    {
        config([
            'subtitles.enrichment.cue_batch_char_budget' => 20,
            'subtitles.enrichment.cue_batch_max_cues' => 100,
        ]);

        $store = app(SubtitleJobArtifactStore::class);
        $job = $this->runningJob();

        // Each cue is 10 chars, so a 20-char budget packs 2 cues per batch.
        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->cues(5, 10));

        $this->assertSame(3, $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES));
        $this->assertSame(['cue-0', 'cue-1'], $this->batchIds($store, $job, 0));
        $this->assertSame(['cue-2', 'cue-3'], $this->batchIds($store, $job, 1));
        $this->assertSame(['cue-4'], $this->batchIds($store, $job, 2));
    }

    public function test_max_cue_count_caps_a_batch_of_short_cues(): void
    {
        config([
            'subtitles.enrichment.cue_batch_char_budget' => 100000,
            'subtitles.enrichment.cue_batch_max_cues' => 3,
        ]);

        $store = app(SubtitleJobArtifactStore::class);
        $job = $this->runningJob();

        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $this->cues(7, 1));

        $this->assertSame(3, $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES));
        $this->assertCount(3, $store->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, 0));
        $this->assertCount(3, $store->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, 1));
        $this->assertCount(1, $store->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, 2));
    }

    public function test_a_single_over_budget_cue_forms_its_own_batch(): void
    {
        config([
            'subtitles.enrichment.cue_batch_char_budget' => 20,
            'subtitles.enrichment.cue_batch_max_cues' => 100,
        ]);

        $store = app(SubtitleJobArtifactStore::class);
        $job = $this->runningJob();

        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, [
            $this->cue('cue-0', str_repeat('a', 5)),
            $this->cue('cue-1', str_repeat('b', 50)),
            $this->cue('cue-2', str_repeat('c', 5)),
        ]);

        // The oversized middle cue neither joins the first batch nor absorbs the
        // trailing cue; it is isolated on its own.
        $this->assertSame(3, $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES));
        $this->assertSame(['cue-0'], $this->batchIds($store, $job, 0));
        $this->assertSame(['cue-1'], $this->batchIds($store, $job, 1));
        $this->assertSame(['cue-2'], $this->batchIds($store, $job, 2));
    }

    public function test_legacy_artifacts_without_a_batch_plan_fall_back_to_fixed_size_chunks(): void
    {
        config(['subtitles.enrichment.cue_batch_size' => 2]);

        $store = app(SubtitleJobArtifactStore::class);
        $job = $this->runningJob();

        // Simulate an artifact written before character-based batching: it
        // carries a batchSize but no batchPlan.
        SubtitleJobArtifact::create([
            'subtitle_job_id' => $job->id,
            'artifact_type' => SubtitleJobArtifactStore::DRAFT_CUES,
            'batch_index' => 0,
            'run_id' => $job->run_id,
            'payload' => [
                'cues' => $this->cues(5, 10),
                'sourceDialect' => 'unknown',
                'batchSize' => 2,
            ],
        ]);

        $this->assertSame(3, $store->batchCount($job, SubtitleJobArtifactStore::DRAFT_CUES));
        $this->assertSame(['cue-0', 'cue-1'], $this->batchIds($store, $job, 0));
        $this->assertSame(['cue-4'], $this->batchIds($store, $job, 2));
    }

    private function runningJob(): SubtitleJob
    {
        return SubtitleJob::factory()->create([
            'run_id' => (string) Str::uuid(),
            'status' => 'running',
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function batchIds(SubtitleJobArtifactStore $store, SubtitleJob $job, int $batchIndex): array
    {
        return array_map(
            static fn (array $cue): string => (string) $cue['cueId'],
            $store->cueBatch($job, SubtitleJobArtifactStore::DRAFT_CUES, $batchIndex),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cues(int $count, int $length): array
    {
        $cues = [];

        for ($index = 0; $index < $count; $index++) {
            $cues[] = $this->cue('cue-'.$index, str_repeat('x', $length));
        }

        return $cues;
    }

    /**
     * @return array<string, mixed>
     */
    private function cue(string $cueId, string $sourceText): array
    {
        return [
            'cueId' => $cueId,
            'index' => 0,
            'startMs' => 0,
            'endMs' => 1000,
            'sourceText' => $sourceText,
            'translatedText' => '',
            'tokens' => [],
        ];
    }
}
