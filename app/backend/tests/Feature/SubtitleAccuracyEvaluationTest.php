<?php

namespace Tests\Feature;

use App\Ai\Agents\TranslationJudgeAgent;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\TranslationAnalysis\TranscriptAccuracyMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SubtitleAccuracyEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalization_and_word_and_character_edits(): void
    {
        $metric = new TranscriptAccuracyMetric;
        $this->assertSame(0, $metric->score('Hello, WORLD!', 'hello world', 'eng')['errorRate']);
        $this->assertSame(0.5, $metric->score('hello world', 'hello', 'eng')['errorRate']);
        $this->assertSame(1, $metric->score('私は猫です。', '私は犬です', 'jpn')['edits']);
        $this->assertSame('cer', $metric->score('猫です', '', 'jpn')['metric']);
        $this->assertSame(1, $metric->score('猫です', '', 'jpn')['errorRate']);
        $this->assertSame(0, $metric->score('ＡＢＣ', 'abc', 'eng')['errorRate']);
    }

    public function test_scores_saved_tracks_and_cached_transcripts_without_prompting(): void
    {
        TranslationJudgeAgent::fake()->preventStrayPrompts();
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        SubtitleTrack::factory()->create(['subtitle_job_id' => $job->id, 'youtube_video_id' => 'synthetic01', 'source_language' => 'eng', 'detected_source_language' => 'eng',
            'cues' => [['sourceText' => 'Hello world.', 'translatedText' => 'Bonjour le monde.', 'tokens' => [['text' => 'Hello'], ['text' => 'world']]]]]);
        $japanese = SubtitleJob::factory()->create(['status' => 'completed']);
        SubtitleTrack::factory()->create(['subtitle_job_id' => $japanese->id, 'youtube_video_id' => 'synthetic02', 'source_language' => 'jpn', 'detected_source_language' => 'jpn',
            'cues' => [['sourceText' => '私は犬です。', 'translatedText' => '', 'tokens' => [['text' => '私'], ['text' => 'は'], ['text' => '犬'], ['text' => 'です']]]]]);
        $this->assertSame(0, Artisan::call('subtitles:eval', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertFalse($report['judge']);
        $this->assertSame(0, $report['results'][0]['errorRate']);
        $this->assertSame(1, $report['results'][0]['boundaryF1']);
        $this->assertSame('cer', $report['results'][1]['metric']);
        $this->assertSame(1, $report['results'][1]['edits']);
        TranslationJudgeAgent::assertNeverPrompted();
        foreach (['synthetic01' => ['eng', 'Hello world.'], 'synthetic02' => ['jpn', '私は猫です。']] as $video => [$language, $text]) {
            CachedVideoTranscript::query()->create(['youtube_video_id' => $video, 'requested_source_language' => $language, 'transcription_model' => 'fake-scribe', 'audio_duration_seconds' => 1,
                'payload' => ['language' => $language, 'segments' => [['text' => $text]]], 'expires_at' => now()->addDay()]);
        }
        $this->assertSame(0, Artisan::call('subtitles:eval', ['--json' => true, '--cached' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame(0, $report['results'][1]['errorRate']);
        $this->assertArrayNotHasKey('boundaryF1', $report['results'][0]);
    }

    public function test_missing_generations_fail_without_making_provider_calls(): void
    {
        TranslationJudgeAgent::fake()->preventStrayPrompts();
        $this->assertSame(1, Artisan::call('subtitles:eval', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame('missing', $report['results'][0]['status']);
        TranslationJudgeAgent::assertNeverPrompted();
    }
}
