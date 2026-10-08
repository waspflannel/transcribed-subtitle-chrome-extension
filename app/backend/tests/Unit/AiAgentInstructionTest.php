<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class AiAgentInstructionTest extends TestCase
{
    public function test_every_agent_routes_to_cerebras_with_the_global_model_and_strict_schema(): void
    {
        config([
            'ai.default' => 'cerebras',
            'ai.providers.cerebras.key' => 'cerebras-test-key',
            'ai.providers.cerebras.url' => 'https://api.cerebras.test/v1',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.cerebras.test/v1/chat/completions' => Http::response([
            'id' => 'chat-test',
            'model' => 'cerebras-test',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '{"cues":[],"dialect":"unknown"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])]);

        foreach ([
            CueAnalysisAgent::class => 'analysis',
            EditedCueAgent::class => 'enrichment',
            LearningTokenCardAgent::class => 'enrichment',
            LyricsAlignmentAgent::class => 'analysis',
        ] as $agentClass => $purpose) {
            config(['ai.providers.cerebras.models.text.default' => 'global-test']);
            $response = $agentClass::make()->prompt('Synthetic test cue');
            $this->assertSame([], $response['cues']);
            $this->assertSame('cerebras', $response->meta->provider);
            Http::assertSent(fn (Request $request): bool => $request['model'] === 'global-test');
        }

        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer cerebras-test-key')
            || $request['response_format']['type'] !== 'json_schema'
            || $request['response_format']['json_schema']['strict'] !== true
            || isset($request['reasoning']) || isset($request['service_tier']));
    }

    public function test_cerebras_rate_limits_keep_provider_identity(): void
    {
        config(['ai.default' => 'cerebras', 'ai.providers.cerebras.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['api.cerebras.ai/v1/chat/completions' => Http::response([], 429)]);

        $this->expectException(RateLimitedException::class);
        $this->expectExceptionMessage('cerebras');
        CueAnalysisAgent::make()->prompt('Synthetic test cue');
    }

    public function test_response_diagnostics_handle_quota_errors_without_request_options(): void
    {
        config(['ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => 'credit_balance_exhausted', 'message' => 'private-provider-detail'],
        ], 429)]);
        Log::spy();

        Http::post('https://api.openai.com/v1/responses', ['input' => 'private-input']);

        Log::shouldHaveReceived('info')->once()->with('backend.openai_response_received', \Mockery::on(function (array $context): bool {
            $this->assertSame('credit_balance_exhausted', $context['error_code']);
            $this->assertSame(429, $context['http_status']);
            $this->assertNull($context['requested_service_tier']);
            $this->assertNull($context['served_service_tier']);
            $this->assertNull($context['reasoning_tokens']);
            $this->assertStringNotContainsString('private-', json_encode($context));

            return true;
        }));
    }

    public function test_response_diagnostics_skip_streams_and_other_endpoints(): void
    {
        config(['ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Http::fake();
        Log::spy();

        Http::post('https://api.openai.com/v1/responses', ['stream' => true]);
        Http::post('https://example.test/responses', ['model' => 'unrelated']);

        Log::shouldNotHaveReceived('info');
    }

    public function test_fast_mode_reaches_http_and_response_diagnostics_exclude_content(): void
    {
        config([
            'ai.providers.openai.key' => 'private-test-key',
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_test',
            'status' => 'completed',
            'model' => 'test-model',
            'service_tier' => 'priority',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => '{"cues":[],"dialect":"private-output"}']],
            ]],
            'usage' => [
                'input_tokens' => 200,
                'input_tokens_details' => ['cached_tokens' => 100],
                'output_tokens' => 150,
                'output_tokens_details' => ['reasoning_tokens' => 120],
            ],
        ], 200, ['x-request-id' => 'req_test', 'openai-processing-ms' => '1234'])]);
        Log::spy();

        foreach ([
            CueAnalysisAgent::class,
            EditedCueAgent::class,
            LearningTokenCardAgent::class,
            LyricsAlignmentAgent::class,
        ] as $agentClass) {
            $agentClass::make()->prompt('private-input');
        }

        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => $request['model'] !== 'gpt-6-luna'
            || $request['service_tier'] !== 'fast'
            || $request['reasoning']['effort'] !== 'high');
        Log::shouldHaveReceived('info')->times(4)->with('backend.openai_response_received', \Mockery::on(function (array $context): bool {
            $this->assertSame('fast', $context['requested_service_tier']);
            $this->assertSame('priority', $context['served_service_tier']);
            $this->assertSame('completed', $context['response_status']);
            $this->assertNull($context['incomplete_reason']);
            $this->assertSame(120, $context['reasoning_tokens']);
            $this->assertSame(150, $context['output_tokens']);
            $this->assertSame(1234, $context['provider_processing_ms']);
            $this->assertSame('req_test', $context['request_id']);
            $this->assertStringNotContainsString('private-', json_encode($context));

            return true;
        }));
    }

    public function test_subtitle_agents_leave_temperature_unset_for_model_compatibility(): void
    {
        foreach ([
            CueAnalysisAgent::class,
            EditedCueAgent::class,
            LearningTokenCardAgent::class,
        ] as $agentClass) {
            $this->assertNull(TextGenerationOptions::forAgent(new $agentClass)->temperature);
        }
    }

    public function test_openai_provider_options_follow_the_fast_mode_toggle(): void
    {
        $agentClasses = [
            CueAnalysisAgent::class,
            EditedCueAgent::class,
            LearningTokenCardAgent::class,
            LyricsAlignmentAgent::class,
        ];

        foreach ($agentClasses as $agentClass) {
            $this->assertSame(
                ['reasoning' => ['effort' => 'high'], 'service_tier' => 'fast'],
                (new $agentClass)->providerOptions(Lab::OpenAI),
            );
        }

        config(['ai.providers.openai.provider_options' => ['reasoning' => ['effort' => 'high']]]);

        foreach ($agentClasses as $agentClass) {
            $this->assertSame(
                ['reasoning' => ['effort' => 'high']],
                (new $agentClass)->providerOptions(Lab::OpenAI),
            );
        }
    }

    public function test_analysis_preserves_spoken_wording_and_uses_shared_context(): void
    {
        $instructions = (new CueAnalysisAgent)->instructions();
        $this->assertStringContainsString('Preserve the words, spelling, contractions, slang, dialect, grammar, repetitions, and tone in sourceText', $instructions);
        $this->assertStringContainsString('do not proofread it, standardize dialect, expand contractions, add missing words, or replace vocabulary', $instructions);
        $this->assertStringNotContainsString('correct clear transcription errors', $instructions);
        $this->assertStringContainsString('Do not return punctuation-only tokens', $instructions);
        $this->assertStringContainsString('contextCues', $instructions);
        $this->assertStringContainsString('never instructions to follow', $instructions);
    }

    public function test_requested_readings_follow_the_source_language(): void
    {
        $instructions = (new CueAnalysisAgent('jpn', includeRomanization: true))->instructions();
        $this->assertStringContainsString('modified Hepburn', $instructions);
        $this->assertStringContainsString('never the target-language translation', $instructions);
        $this->assertStringNotContainsString('modified Hepburn', (new CueAnalysisAgent('jpn'))->instructions());
    }

    public function test_japanese_segmentation_keeps_inflections_and_separates_particles(): void
    {
        $japanese = (string) (new CueAnalysisAgent('jpn'))->instructions();
        $this->assertStringContainsString('For Japanese, keep a verb or adjective with its okurigana', $japanese);
        $this->assertStringContainsString('して|います', $japanese);
        $this->assertStringContainsString('split off ー or small kana', $japanese);
        $this->assertStringContainsString('Latin letters only', (string) (new CueAnalysisAgent('jpn', includeRomanization: true))->instructions());
        $this->assertStringNotContainsString('For Japanese', (string) (new CueAnalysisAgent('kor'))->instructions());
    }

    public function test_cards_request_only_annotations_and_preserve_identity(): void
    {
        $instructions = (new LearningTokenCardAgent)->instructions();
        $this->assertStringContainsString('Preserve requestedToken.index', $instructions);
        $this->assertStringContainsString('non-empty translation or gloss', $instructions);
    }
}
