<?php

namespace Tests\Feature;

use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Codex\CodexService;
use App\Services\TranslationAnalysis\LearningTokenEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CodexJobSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_pin_codex_model_and_fast_mode_and_reuse_only_the_same_selection(): void
    {
        Queue::fake();
        $codex = $this->mock(CodexService::class);
        $codex->shouldReceive('validateSelection')->with('codex-model', false)->twice();
        $codex->shouldReceive('validateSelection')->with('codex-model', true)->once();
        $codex->shouldReceive('validateSelection')->with('other-model', false)->once();
        $codex->shouldReceive('requireConnected')->times(3);
        $this->withExtensionInstall('install_codex_test');

        $standard = $this->postJson('/v1/subtitle-jobs', $this->payload())->assertAccepted()
            ->assertJsonPath('aiProvider', 'codex')->assertJsonPath('aiModel', 'codex-model')->assertJsonPath('aiFastMode', false);
        $fast = $this->postJson('/v1/subtitle-jobs', [...$this->payload(), 'aiFastMode' => true])->assertAccepted()
            ->assertJsonPath('aiFastMode', true);
        $other = $this->postJson('/v1/subtitle-jobs', [...$this->payload(), 'aiModel' => 'other-model'])->assertAccepted();
        $this->postJson('/v1/subtitle-jobs', $this->payload())->assertAccepted()->assertJsonPath('jobId', $standard->json('jobId'));

        $this->assertNotSame($standard->json('jobId'), $fast->json('jobId'));
        $this->assertNotSame($standard->json('jobId'), $other->json('jobId'));
        $job = SubtitleJob::where('public_id', $fast->json('jobId'))->firstOrFail();
        $selection = SubtitleModel::forJob($job);
        $this->assertSame(['codex', 'codex-model', true], [$selection->provider, $selection->model, $selection->fastMode]);
        $this->getJson('/v1/subtitle-jobs/'.$job->public_id)->assertOk()->assertJsonPath('aiFastMode', true);
        $this->getJson('/v1/subtitle-jobs')->assertOk()->assertJsonCount(3, 'jobs')
            ->assertJsonFragment(['aiProvider' => 'codex', 'aiModel' => 'codex-model', 'aiFastMode' => true]);
        Queue::assertPushed(AcquireSubtitleAudio::class, 3);
        Http::assertNothingSent();
    }

    #[TestWith(['openai'])]
    #[TestWith(['cerebras'])]
    public function test_api_jobs_keep_the_fixed_model_and_accept_false_fast_mode(string $provider): void
    {
        Queue::fake();
        $payload = $this->payload();
        unset($payload['aiModel']);
        $response = $this->withExtensionInstall('install_codex_test')
            ->postJson('/v1/subtitle-jobs', [...$payload, 'aiProvider' => $provider, 'aiFastMode' => false])
            ->assertAccepted()->assertJsonPath('aiProvider', $provider)
            ->assertJsonPath('aiModel', config("ai.providers.{$provider}.models.text.default"))->assertJsonPath('aiFastMode', false);
        $job = SubtitleJob::where('public_id', $response->json('jobId'))->firstOrFail();
        $this->assertSame(hash('sha256', json_encode([
            $job->youtube_video_id, $job->source_language, $job->target_language,
            $job->processing_version, $job->transcription_options_hash, $job->ai_provider, $job->ai_model,
        ], JSON_THROW_ON_ERROR)), $job->reuse_key);
    }

    #[TestWith(['aiModel', null])]
    #[TestWith(['aiModel', ''])]
    #[TestWith(['aiModel', '--unsafe-model'])]
    #[TestWith(['aiFastMode', 'true'])]
    #[TestWith(['aiFastMode', 1])]
    public function test_invalid_codex_options_never_create_a_job(string $field, mixed $value): void
    {
        $this->withExtensionInstall('install_codex_test')
            ->postJson('/v1/subtitle-jobs', [...$this->payload(), $field => $value])->assertUnprocessable();
        $this->assertDatabaseCount('subtitle_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_api_model_and_fast_mode_overrides_are_rejected(): void
    {
        $this->withExtensionInstall('install_codex_test');
        $payload = [...$this->payload(), 'aiProvider' => 'openai'];
        $this->postJson('/v1/subtitle-jobs', $payload)->assertUnprocessable();
        unset($payload['aiModel']);
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiFastMode' => true])->assertUnprocessable();
        $this->assertDatabaseCount('subtitle_jobs', 0);
    }

    public function test_codex_failure_does_not_create_or_fall_back_to_an_api_job(): void
    {
        Queue::fake();
        $this->mock(CodexService::class)->shouldReceive('validateSelection')->once()
            ->andThrow(new SubtitleProcessingException('provider_not_configured', 'Sign in to Codex.', 422));
        $this->withExtensionInstall('install_codex_test')->postJson('/v1/subtitle-jobs', $this->payload())
            ->assertUnprocessable()->assertJsonPath('error.code', 'provider_not_configured');
        $this->assertDatabaseCount('subtitle_jobs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_codex_still_requires_elevenlabs_for_transcription(): void
    {
        config(['ai.providers.eleven.key' => null]);
        $this->withExtensionInstall('install_codex_test')->postJson('/v1/subtitle-jobs', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('subtitle_jobs', 0);
    }

    public function test_word_card_cache_distinguishes_saved_fast_mode(): void
    {
        $codex = $this->mock(CodexService::class);
        $codex->shouldReceive('requireConnected')->twice();
        $codex->shouldReceive('prompt')->twice()->andReturnUsing(fn ($agent, $input, SubtitleModel $selection): array => [
            'token' => ['index' => 0, 'translation' => $selection->fastMode ? 'Fast meaning' : 'Standard meaning'],
        ]);
        foreach ([false, true, false] as $fastMode) {
            $job = SubtitleJob::factory()->create(['status' => 'completed', 'ai_provider' => 'codex', 'ai_model' => 'saved-model', 'ai_fast_mode' => $fastMode]);
            $track = SubtitleTrack::factory()->create([
                'subtitle_job_id' => $job->id, 'source_language' => 'eng', 'detected_source_language' => 'eng', 'target_language' => 'spa',
                'cues' => [['cueId' => 'cue-0', 'index' => 0, 'sourceText' => 'Hello', 'translatedText' => 'Hola', 'startMs' => 0, 'endMs' => 1000,
                    'tokens' => [['index' => 0, 'text' => 'Hello', 'normalizedText' => 'hello']]]],
            ]);
            $result = app(LearningTokenEnrichmentService::class)->enrich(['trackId' => $track->public_id, 'cueId' => 'cue-0', 'tokenIndex' => 0]);
            $this->assertSame($fastMode ? 'Fast meaning' : 'Standard meaning', $result['token']['translation']);
        }
        Http::assertNothingSent();
    }

    private function payload(): array
    {
        return [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            'includeRomanization' => false,
            'includeTranslation' => false,
            'aiProvider' => 'codex',
            'aiModel' => 'codex-model',
        ];
    }
}
