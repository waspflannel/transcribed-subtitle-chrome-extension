<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitlePartialTrackAssembler;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SubtitlePartialTrackCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchanged_polls_read_only_the_lightweight_preview(): void
    {
        $job = SubtitleJob::factory()->create();
        $store = app(SubtitleJobArtifactStore::class);
        $assembler = app(SubtitlePartialTrackAssembler::class);
        $cues = [$this->cue(0), $this->cue(1)];
        $store->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $cues);
        $cues[0]['tokens'] = [['index' => 0, 'text' => 'Word', 'normalizedText' => 'word']];
        $cues[0]['translatedText'] = 'Meaning';
        $store->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0, new CueEnrichmentResult([$cues[0]], 'unknown'));
        $preview = $assembler->assemble($job);
        $this->assertArrayNotHasKey('tokens', $preview['cues'][0]);
        $this->assertSame('Meaning', $preview['cues'][0]['translatedText']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame($preview, $assembler->assemble($job));
            $queries = DB::getQueryLog();
            $this->assertCount(1, $queries);
            $this->assertContains(SubtitleJobArtifactStore::PARTIAL_TRACK, $queries[0]['bindings']);
            $this->assertStringStartsWith('select "payload"', $queries[0]['query']);
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_out_of_order_analysis_and_appended_drafts_invalidate_the_preview(): void
    {
        $job = SubtitleJob::factory()->create();
        $store = app(SubtitleJobArtifactStore::class);
        $assembler = app(SubtitlePartialTrackAssembler::class);
        $cues = [$this->cue(0), $this->cue(1)];
        $store->appendDraftCues($job, $cues);
        $initial = $assembler->assemble($job);
        $this->assertSame(0, $initial['readyThroughMs']);

        $store->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 1,
            new CueEnrichmentResult([[...$cues[1], 'translatedText' => 'Second']], 'unknown'));
        $second = $assembler->assemble($job);
        $this->assertGreaterThan($initial['revision'], $second['revision']);
        $this->assertSame(0, $second['readyThroughMs']);
        $this->assertSame('Second', $second['cues'][1]['translatedText']);

        $store->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0,
            new CueEnrichmentResult([[...$cues[0], 'translatedText' => 'First']], 'unknown'));
        $analyzed = $assembler->assemble($job);
        $this->assertSame(2000, $analyzed['readyThroughMs']);
        $this->assertGreaterThan($second['revision'], $analyzed['revision']);

        $store->appendDraftCues($job, [...$cues, $this->cue(2)]);
        $extended = $assembler->assemble($job);
        $this->assertCount(3, $extended['cues']);
        $this->assertSame(2000, $extended['readyThroughMs']);
        $this->assertSame(array_slice($extended['cues'], 0, 2), $analyzed['cues']);
        $this->assertGreaterThan($analyzed['revision'], $extended['revision']);
    }

    public function test_rollback_run_replacement_and_cleanup_preserve_preview_ownership(): void
    {
        $job = SubtitleJob::factory()->create();
        $store = app(SubtitleJobArtifactStore::class);
        $assembler = app(SubtitlePartialTrackAssembler::class);
        $store->appendDraftCues($job, [$this->cue(0)]);
        $original = $assembler->assemble($job);
        try {
            DB::transaction(function () use ($job, $store): void {
                $store->appendDraftCues($job, [$this->cue(0), $this->cue(1)]);
                throw new RuntimeException('Rollback this write');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback this write', $exception->getMessage());
        }
        $this->assertSame($original, $assembler->assemble($job));

        $old = clone $job;
        $job->update(['run_id' => (string) Str::uuid()]);
        $this->assertNull($assembler->assemble($job));
        $store->appendDraftCues($job, [$this->cue(0)]);
        $replacement = $assembler->assemble($job);
        $store->putCueCollection($old, SubtitleJobArtifactStore::DRAFT_CUES, [$this->cue(1)]);
        $store->deleteForJob($old);
        $this->assertSame($replacement, $assembler->assemble($job));

        $job->update(['status' => 'failed']);
        $store->deleteForJob($job);
        $this->assertNull($assembler->assemble($job));
        $this->assertSame(0, SubtitleJobArtifact::query()->where('subtitle_job_id', $job->id)->count());
    }

    private function cue(int $index): array
    {
        return ['cueId' => 'cue-'.$index, 'index' => $index, 'startMs' => $index * 1000,
            'endMs' => ($index + 1) * 1000, 'sourceText' => 'Word '.$index];
    }
}
