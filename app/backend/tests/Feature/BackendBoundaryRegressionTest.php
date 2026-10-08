<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use App\Models\InstanceSetting;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class BackendBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_setup_preserves_raw_and_quoted_existing_keys(): void
    {
        $path = $this->app->environmentFilePath();
        $original = file_get_contents($path);
        $encoded = 'base64:'.base64_encode(str_repeat('k', 32));
        try {
            foreach ([str_repeat('k', 32), $encoded, '"'.$encoded.'"', "'".$encoded."'"] as $value) {
                $contents = 'APP_KEY='.$value.PHP_EOL;
                file_put_contents($path, $contents);
                config(['app.key' => Dotenv::parse($contents)['APP_KEY']]);

                $this->assertSame(0, Artisan::call('instance:ensure-key'));
                $this->assertSame($contents, file_get_contents($path));
            }
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function test_key_setup_generates_only_once_and_reports_an_unwritable_key_slot(): void
    {
        $path = $this->app->environmentFilePath();
        $original = file_get_contents($path);
        try {
            file_put_contents($path, 'APP_KEY='.PHP_EOL);
            config(['app.key' => '']);
            $this->assertSame(0, Artisan::call('instance:ensure-key'));
            $key = config('app.key');
            $this->assertStringStartsWith('base64:', $key);
            $contents = file_get_contents($path);
            $this->assertSame(0, Artisan::call('instance:ensure-key'));
            $this->assertSame($contents, file_get_contents($path));

            file_put_contents($path, 'APP_NAME=Test'.PHP_EOL);
            config(['app.key' => '']);
            $this->assertSame(1, Artisan::call('instance:ensure-key'));
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function test_cached_empty_key_cannot_replace_an_existing_environment_key(): void
    {
        $path = $this->app->environmentFilePath();
        $original = file_get_contents($path);
        $cache = $this->app->getCachedConfigPath();
        try {
            $contents = 'APP_KEY="base64:'.base64_encode(str_repeat('k', 32)).'"'.PHP_EOL;
            file_put_contents($path, $contents);
            file_put_contents($cache, '<?php return [];');
            $this->app->instance('config_loaded_from_cache', true);
            config(['app.key' => '']);

            $this->assertSame(1, Artisan::call('instance:ensure-key'));
            $this->assertStringContainsString('config:clear', Artisan::output());
            $this->assertSame($contents, file_get_contents($path));
        } finally {
            unlink($cache);
            file_put_contents($path, $original);
        }
    }

    public function test_long_supported_urls_are_canonicalized_on_creation_and_retry(): void
    {
        Bus::fake();
        $canonical = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $payload = [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => $canonical.'&feature='.str_repeat('x', 300),
            'sourceLanguage' => 'spa', 'targetLanguage' => 'eng',
            'includeRomanization' => true, 'includeTranslation' => true,
        ];
        $this->withExtensionInstall('boundary_url_test')
            ->postJson('/v1/subtitle-jobs', $payload)->assertAccepted();
        $job = SubtitleJob::query()->sole();
        $this->assertSame($canonical, $job->youtube_url);
        $runId = $job->run_id;
        $job->update(['status' => 'failed']);

        $payload['youtubeUrl'] = 'https://youtu.be/dQw4w9WgXcQ?feature='.str_repeat('y', 300);
        $this->postJson('/v1/subtitle-jobs', $payload)->assertAccepted();
        $this->assertDatabaseCount('subtitle_jobs', 1);
        $this->assertSame($canonical, $job->refresh()->youtube_url);
        $this->assertNotSame($runId, $job->run_id);
    }

    public function test_obsolete_partial_flag_is_rejected_before_replacing_a_track(): void
    {
        Bus::fake();
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $job->id]);
        foreach ([true, false, null] as $value) {
            $this->withExtensionInstall('boundary_lyrics_test')
                ->postJson('/v1/subtitle-jobs/'.$job->public_id.'/lyrics', [
                    'expectedTrackId' => $track->public_id,
                    'lyrics' => 'Replacement words',
                    'allowPartial' => $value,
                ])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['errors' => ['allowPartial']]]]);
        }
        $this->assertDatabaseCount('subtitle_track_lyrics_corrections', 0);
        $this->assertSame($track->public_id, $track->fresh()->public_id);
        Bus::assertNothingDispatched();
    }

    public function test_runtime_check_renders_queue_arrays_without_json_mode(): void
    {
        $this->assertSame(0, Artisan::call('subtitles:runtime-check'));
        $output = Artisan::output();
        $this->assertStringContainsString('subtitleWorkerGroups', $output);
        $this->assertStringContainsString('subtitle-generation', $output);
    }

    public function test_evaluators_load_keys_saved_in_instance_settings(): void
    {
        InstanceSetting::query()->create(['id' => 1, 'values' => ['providers' => ['openai' => ['apiKey' => 'saved-eval-key']]]]);
        config(['ai.default' => 'openai', 'ai.providers.openai.key' => null]);
        CueAnalysisAgent::fake(function (string $prompt): array {
            $input = json_decode($prompt, true);

            return ['cues' => array_map(fn (array $cue): array => [
                'cueId' => $cue['cueId'], 'index' => $cue['index'],
                'tokens' => [['index' => 0, 'text' => $cue['sourceText']]],
            ], $input['cues'])];
        })->preventStrayPrompts();
        Artisan::call('subtitles:eval-tokenization', ['--lang' => 'jpn', '--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($report['languages']['jpn']['cues']);
        $this->assertSame('saved-eval-key', config('ai.providers.openai.key'));
        CueAnalysisAgent::assertPrompted(fn (): bool => true);

        config(['ai.providers.openai.key' => null]);
        LyricsAlignmentAgent::fake(fn (): array => ['cues' => []])->preventStrayPrompts();
        $this->assertSame(0, Artisan::call('subtitles:eval-agents', ['--agent' => 'lyrics']));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['cases'][0]['runs'][0]['pipelineCompleted']);
        $this->assertSame('saved-eval-key', config('ai.providers.openai.key'));
        LyricsAlignmentAgent::assertPrompted(fn (): bool => true);
    }

    public function test_removed_enrichment_evaluator_is_rejected(): void
    {
        $this->assertSame(1, Artisan::call('subtitles:eval-agents', ['--agent' => 'enrichment']));
        $this->assertStringContainsString('Choose a supported agent', Artisan::output());
    }

    public function test_metrics_separate_standard_and_fast_runs_of_the_same_model(): void
    {
        foreach ([false, true] as $fast) {
            $job = SubtitleJob::factory()->create([
                'status' => 'completed', 'ai_provider' => 'codex',
                'ai_model' => 'gpt-test', 'ai_fast_mode' => $fast,
            ]);
            SubtitleJobEvent::query()->create([
                'subtitle_job_id' => $job->id, 'run_id' => $job->run_id,
                'event' => 'job.completed', 'duration_ms' => $fast ? 1000 : 2000,
            ]);
        }

        $this->assertSame(0, Artisan::call('subtitles:metrics', ['--json' => true]));
        $groups = json_decode(Artisan::output(), true)['groups'];
        $this->assertCount(2, $groups);
        $this->assertSame([false, true], array_column($groups, 'aiFastMode'));
        $this->assertSame([2000, 1000], array_column($groups, 'p50DurationMs'));
        $this->assertSame([1, 1], array_column($groups, 'completedJobCount'));
        $this->assertSame(0, Artisan::call('subtitles:metrics'));
        $this->assertStringContainsString('fast', Artisan::output());
    }
}
