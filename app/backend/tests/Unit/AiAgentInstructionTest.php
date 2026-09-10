<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class AiAgentInstructionTest extends TestCase
{
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
            CueRomanizationAgent::class,
            CueTokenizationAgent::class,
            CueEnrichmentAgent::class,
            EditedCueAgent::class,
            LearningTokenCardAgent::class,
            LyricsAlignmentAgent::class,
        ] as $agentClass) {
            $agentClass::make()->prompt('private-input');
        }

        Http::assertSentCount(7);
        Http::assertNotSent(fn (Request $request): bool => $request['model'] !== 'gpt-5.6-luna'
            || $request['service_tier'] !== 'fast'
            || $request['reasoning']['effort'] !== 'low');
        Log::shouldHaveReceived('info')->times(7)->with('backend.openai_response_received', \Mockery::on(function (array $context): bool {
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
            CueEnrichmentAgent::class,
            CueRomanizationAgent::class,
            CueTokenizationAgent::class,
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
            CueEnrichmentAgent::class,
            CueRomanizationAgent::class,
            CueTokenizationAgent::class,
            EditedCueAgent::class,
            LearningTokenCardAgent::class,
            LyricsAlignmentAgent::class,
        ];

        foreach ($agentClasses as $agentClass) {
            $this->assertSame(
                ['reasoning' => ['effort' => 'low'], 'service_tier' => 'fast'],
                (new $agentClass)->providerOptions(Lab::OpenAI),
            );
        }

        config(['ai.providers.openai.provider_options' => ['reasoning' => ['effort' => 'low']]]);

        foreach ($agentClasses as $agentClass) {
            $this->assertSame(
                ['reasoning' => ['effort' => 'low']],
                (new $agentClass)->providerOptions(Lab::OpenAI),
            );
        }
    }

    public function test_tokenization_agent_owns_stable_token_boundary_rules(): void
    {
        $instructions = (new CueTokenizationAgent)->instructions();

        $this->assertStringContainsString('Return one tokenized cue for each input cue in the same order.', $instructions);
        $this->assertStringContainsString('Do not return punctuation-only tokens.', $instructions);
        $this->assertStringContainsString('Do not censor profanity', $instructions);
        $this->assertStringContainsString('transcription artifacts', $instructions);
        $this->assertStringContainsString('Every token must begin and end on a word boundary of the source language.', $instructions);

        $this->assertStringContainsString('Orphan fragment', $instructions);
        $this->assertStringContainsString('never strand a single kana that is part of a neighboring content word.', $instructions);
        $this->assertStringContainsString('Truncated word', $instructions);
        $this->assertStringContainsString('Sokuon', $instructions);
        $this->assertStringContainsString('Never drop a leading character to emit うて.', $instructions);

        $this->assertStringContainsString('Mandarin examples:', $instructions);
        $this->assertStringContainsString('Split 我喜欢学习中文 as 我 / 喜欢 / 学习 / 中文', $instructions);
        $this->assertStringContainsString('Thai examples:', $instructions);
        $this->assertStringContainsString('Split ผมชอบกินข้าว as ผม / ชอบ / กิน / ข้าว', $instructions);
    }

    public function test_romanization_agent_owns_stable_romanization_rules(): void
    {
        $instructions = (new CueRomanizationAgent)->instructions();

        $this->assertStringContainsString('Do not translate, retokenize', $instructions);
        $this->assertStringContainsString('Preserve cueId and cue index exactly.', $instructions);
        $this->assertStringContainsString('return the same index', $instructions);
        $this->assertStringContainsString('Do not echo the token text.', $instructions);
        $this->assertStringContainsString('Hepburn for Japanese and pinyin for Mandarin', $instructions);
    }

    public function test_analysis_agent_owns_stable_tokenization_and_translation_rules(): void
    {
        $instructions = (new CueAnalysisAgent)->instructions();

        $this->assertStringContainsString('Return one analyzed cue for each input cue in the same order.', $instructions);
        $this->assertStringContainsString('Do not return punctuation-only tokens.', $instructions);
        $this->assertStringContainsString('Do not censor profanity', $instructions);
        $this->assertStringContainsString('transcription artifacts', $instructions);
        $this->assertStringContainsString('Every token must begin and end on a word boundary of the source language.', $instructions);
        $this->assertStringContainsString('Orphan fragment', $instructions);
        $this->assertStringContainsString('Sokuon', $instructions);
        $this->assertStringContainsString('Mandarin examples:', $instructions);
        $this->assertStringContainsString('Thai examples:', $instructions);
        $this->assertStringContainsString('Translate the intended subtitle meaning', $instructions);
        $this->assertStringContainsString('colloquial, dialectal, romanized, poetic, musical, slang, or idiomatic text', $instructions);
        $this->assertStringContainsString('Use previousCueText and nextCueText', $instructions);
    }

    public function test_enrichment_agent_owns_stable_learning_metadata_rules(): void
    {
        $instructions = (new CueEnrichmentAgent)->instructions();

        $this->assertStringContainsString('Return one enriched cue for each input cue in the same order.', $instructions);
        $this->assertStringContainsString('Return exactly one token for each input token in the same order.', $instructions);
        $this->assertStringContainsString('The cue translation is owned by the server and is NOT part of your output', $instructions);
        $this->assertStringContainsString('Obey includeRomanization from the input.', $instructions);
    }

    public function test_learning_token_card_agent_owns_stable_card_rules(): void
    {
        $instructions = (new LearningTokenCardAgent)->instructions();

        $this->assertStringContainsString('Return exactly one token object for requestedToken.', $instructions);
        $this->assertStringContainsString('requestedToken.text exactly', $instructions);
        $this->assertStringContainsString('For Latin-script languages, omit romanization unless it helps pronunciation.', $instructions);
    }
}
