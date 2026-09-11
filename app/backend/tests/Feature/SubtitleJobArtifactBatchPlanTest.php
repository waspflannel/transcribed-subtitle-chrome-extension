<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
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

    private function runningJob(): SubtitleJob
    {
        return SubtitleJob::factory()->create([
            'run_id' => (string) Str::uuid(),
            'status' => 'running',
        ]);
    }

    public function test_balancing_preserves_call_count_limits_and_every_unicode_cue_in_order(): void
    {
        $store = app(SubtitleJobArtifactStore::class);
        $texts = ['tiny', '日本語の字幕を読む', 'مرحبا بالعالم', 'ภาษาไทย', 'ສະບາຍດີ', 'é', '', str_repeat('字幕', 30)];
        $cues = [];
        for ($index = 0; $index < 37; $index++) {
            $cues[] = $this->cue('cue-'.$index, $texts[$index % count($texts)]);
        }

        foreach ([[23, 3], [100, 7], [1000, 20], [1, 1]] as [$charBudget, $maxCues]) {
            config([
                'subtitles.enrichment.cue_batch_char_budget' => $charBudget,
                'subtitles.enrichment.cue_batch_max_cues' => $maxCues,
                'subtitles.enrichment.balanced_batches' => false,
            ]);
            $baseline = $store->batchPlan($cues);
            config(['subtitles.enrichment.balanced_batches' => true]);
            $balanced = $store->batchPlan($cues);
            $this->assertCount(count($baseline), $balanced);
            $collected = [];

            foreach ($balanced as [$start, $end]) {
                $this->assertSame(count($collected), $start);
                $batch = array_slice($cues, $start, $end - $start + 1);
                $this->assertNotEmpty($batch);
                $this->assertLessThanOrEqual($maxCues, count($batch));
                $chars = array_sum(array_map(fn (array $cue): int => mb_strlen($cue['sourceText']), $batch));
                if ($chars > $charBudget) {
                    $this->assertCount(1, $batch, 'An oversized cue must remain isolated.');
                }
                array_push($collected, ...$batch);
            }

            $this->assertSame($cues, $collected);
        }
    }

    public function test_balancing_reduces_the_largest_estimated_response_without_adding_a_call(): void
    {
        config([
            'subtitles.enrichment.cue_batch_char_budget' => 1000,
            'subtitles.enrichment.cue_batch_max_cues' => 4,
            'subtitles.enrichment.balanced_batches' => true,
        ]);
        $job = new SubtitleJob(['include_translation' => false, 'include_romanization' => true]);
        $cues = [];
        foreach ([...array_fill(0, 4, str_repeat('字', 10)), ...array_fill(0, 3, 'abcdefghij')] as $index => $text) {
            $cues[] = $this->cue('cue-'.$index, $text);
        }

        $balanced = app(SubtitleJobArtifactStore::class)->batchPlan($cues, $job);

        $this->assertSame([[0, 2], [3, 6]], $balanced);
        // The fixture's per-cue estimates are 202 for Japanese and 66 for Latin.
        // The largest batch falls from four Japanese cues to three.
        $weights = [202, 202, 202, 202, 66, 66, 66];
        $batchWork = array_map(fn (array $bounds): int => array_sum(array_slice($weights, $bounds[0], $bounds[1] - $bounds[0] + 1)), $balanced);
        $this->assertLessThan(4 * 202, max($batchWork));
    }

    public function test_balancing_keeps_empty_and_single_batch_plans_unchanged(): void
    {
        config(['subtitles.enrichment.balanced_batches' => true]);
        $store = app(SubtitleJobArtifactStore::class);

        $this->assertSame([], $store->batchPlan([]));
        $this->assertSame([[0, 0]], $store->batchPlan([$this->cue('single', str_repeat('字', 2000))]));
        $this->assertSame([[0, 1]], $store->batchPlan($this->cues(2, 10)));
    }

    public function test_balancing_preserves_the_existing_empty_text_fallback(): void
    {
        config([
            'subtitles.enrichment.balanced_batches' => true,
            'subtitles.enrichment.cue_batch_max_cues' => 2,
        ]);

        $this->assertSame([[0, 1], [2, 3]], app(SubtitleJobArtifactStore::class)->batchPlan([
            ['sourceText' => null],
            ['sourceText' => 123],
            ['sourceText' => []],
            [],
        ]));
    }

    public function test_missing_analysis_batch_cannot_be_published_as_a_complete_track(): void
    {
        config(['subtitles.enrichment.cue_batch_max_cues' => 1]);
        $store = app(SubtitleJobArtifactStore::class);
        $job = $this->runningJob();
        $cues = $this->cues(2, 5);
        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $cues);
        $store->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0, new CueEnrichmentResult([$cues[0]], 'unknown'));
        $this->expectException(SubtitleProcessingException::class);
        $store->cueResultFromBatchArtifacts($job, SubtitleJobArtifactStore::ANALYZED_CUES);
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
