<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use App\Models\SubtitleJob;
use App\Services\Audio\YouTubeCreatorCaptions;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobService;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class CreatorCaptionAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_manual_source_language_captions_are_downloaded_and_parsed(): void
    {
        $job = SubtitleJob::factory()->create(['source_language' => 'eng', 'use_creator_captions' => true]);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (in_array('--dump-single-json', $process->command, true)) {
                return Process::result(json_encode(['subtitles' => ['en' => []], 'automatic_captions' => ['en' => []]]));
            }
            $this->assertContains('--write-subs', $process->command);
            $this->assertContains('--no-write-auto-subs', $process->command);
            $this->assertNotContains('--write-auto-subs', $process->command);
            $path = $process->command[array_search('--paths', $process->command) + 1];
            File::copy(base_path('tests/Fixtures/captions/manual.en.vtt'), $path.'/captions.en.vtt');

            return Process::result();
        });
        $captions = app(YouTubeCreatorCaptions::class)->fetch($job);
        $this->assertSame('eng', $captions['language']);
        $this->assertSame("Hello world & friends.\nGoodbye.", $captions['text']);
        Process::fake(fn () => Process::result(json_encode(['automatic_captions' => ['en' => []]])));
        $this->assertNull(app(YouTubeCreatorCaptions::class)->fetch($job));
        Process::fake(fn () => Process::result(exitCode: 1));
        $this->assertNull(app(YouTubeCreatorCaptions::class)->fetch($job));
    }

    public function test_caption_preference_is_pinned_and_distinguishes_job_reuse(): void
    {
        Queue::fake();
        $payload = json_decode(file_get_contents(base_path('../../packages/contracts/fixtures/valid-create-subtitle-job-request.json')), true);
        $headers = ['X-Extension-Install-Id' => 'install_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
        config(['subtitles.creator_captions' => true]);
        $first = $this->withHeaders($headers)->postJson('/v1/subtitle-jobs', $payload)->assertAccepted();
        $job = SubtitleJob::where('public_id', $first->json('jobId'))->firstOrFail();
        $this->assertTrue($job->use_creator_captions);
        config(['subtitles.creator_captions' => false]);
        $second = $this->withHeaders($headers)->postJson('/v1/subtitle-jobs', $payload)->assertAccepted();
        $other = SubtitleJob::where('public_id', $second->json('jobId'))->firstOrFail();
        $this->assertNotSame($job->id, $other->id);
        $this->assertFalse($other->use_creator_captions);
        $this->assertTrue($job->refresh()->use_creator_captions);
    }

    public function test_unknown_source_defers_manual_caption_selection_until_scribe_detects_language(): void
    {
        $job = SubtitleJob::factory()->create(['source_language' => 'auto', 'use_creator_captions' => true]);
        Process::fake(fn () => Process::result(json_encode(['subtitles' => ['en' => [], 'ja' => []]])));
        $this->assertSame(['deferred' => true], app(YouTubeCreatorCaptions::class)->fetch($job));
    }

    public function test_creator_text_uses_shared_alignment_and_falls_back_on_invalid_alignment(): void
    {
        $job = SubtitleJob::factory()->create(['source_language' => 'eng', 'use_creator_captions' => true]);
        $artifacts = app(SubtitleJobArtifactStore::class);
        $artifacts->putCreatorCaptions($job, ['language' => 'eng', 'text' => 'Hello world.']);
        $transcript = new TimestampedTranscript('eng', 2, [new TimestampedTranscriptSegment(0, 2, 'Helo world.')], 'WEBVTT');
        $method = new ReflectionMethod(SubtitleGenerationPipeline::class, 'captionCues');
        LyricsAlignmentAgent::fake(function (string $prompt): array {
            $input = json_decode($prompt, true);

            return ['cues' => [['cueId' => $input['cues'][0]['cueId'], 'endPartIndex' => count($input['lyricsParts']) - 1]]];
        })->preventStrayPrompts();
        $cues = $method->invoke(app(SubtitleGenerationPipeline::class), $job, $transcript);
        $this->assertSame('Hello world.', $cues[0]['sourceText']);
        $this->assertSame(0, $cues[0]['startMs']);
        $this->assertSame(2000, $cues[0]['endMs']);
        $this->assertSame('creator_captions', $job->refresh()->transcript_source);
        LyricsAlignmentAgent::fake([['cues' => []]])->preventStrayPrompts();
        $this->assertSame('Helo world.', $method->invoke(app(SubtitleGenerationPipeline::class), $job, $transcript)[0]['sourceText']);
        $this->assertSame('scribe', $job->refresh()->transcript_source);
        $this->assertNotSame(SubtitleJobService::processingVersionFor(false, false, true), SubtitleJobService::processingVersionFor(false, false, false));
    }

    public function test_full_transcript_is_a_bounded_stable_prompt_prefix_only_after_merge(): void
    {
        $job = SubtitleJob::factory()->create(['source_language' => 'eng']);
        $cue = ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 1000, 'sourceText' => 'Hello'];
        $prompts = [];
        CueAnalysisAgent::fake(function (string $prompt) use (&$prompts, $cue): array {
            $prompts[] = json_decode($prompt, true);

            return ['cues' => [[...$cue, 'tokens' => [['index' => 0, 'text' => 'Hello']]]]];
        })->preventStrayPrompts();
        $provider = app(LaravelAiTranslationAnalysisProvider::class);
        $provider->analyzeCueBatch([$cue], [$cue], 'eng', 'fra', false, job: $job);
        $this->assertArrayNotHasKey('fullTranscript', $prompts[0]);
        app(SubtitleJobArtifactStore::class)->putTranscript($job, new TimestampedTranscript('eng', 1, [new TimestampedTranscriptSegment(0, 1, 'Hello world')], 'WEBVTT'));
        config(['subtitles.translation_context.max_characters' => 5]);
        $provider->analyzeCueBatch([$cue], [$cue], 'eng', 'fra', false, job: $job);
        $this->assertSame('fullTranscript', array_key_first($prompts[1]));
        $this->assertSame('Hello', $prompts[1]['fullTranscript']);
        config(['subtitles.translation_context.enabled' => false]);
        $provider->analyzeCueBatch([$cue], [$cue], 'eng', 'fra', false, job: $job);
        $this->assertArrayNotHasKey('fullTranscript', $prompts[2]);
    }
}
