<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use App\Ai\SubtitlePromptRules;
use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class AiAgentInstructionTest extends TestCase
{
    public function test_every_agent_routes_to_cerebras_with_its_task_model_and_strict_schema(): void
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
            CueTokenizationAgent::class => 'tokenization',
            CueRomanizationAgent::class => 'romanization',
            CueEnrichmentAgent::class => 'enrichment',
            EditedCueAgent::class => 'enrichment',
            LearningTokenCardAgent::class => 'enrichment',
            LyricsAlignmentAgent::class => 'analysis',
        ] as $agentClass => $purpose) {
            config(["ai.providers.cerebras.models.{$purpose}.default" => $purpose.'-test']);
            $response = $agentClass::make()->prompt('Synthetic test cue');
            $this->assertSame([], $response['cues']);
            $this->assertSame('cerebras', $response->meta->provider);
            Http::assertSent(fn (Request $request): bool => $request['model'] === $purpose.'-test');
        }

        Http::assertSentCount(7);
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

    public function test_segmentation_agents_share_coverage_and_only_relevant_language_examples(): void
    {
        foreach (['jpn' => 'Japanese examples:', 'cmn' => 'Chinese examples:', 'tha' => 'Thai example:'] as $language => $example) {
            foreach ([new CueTokenizationAgent($language), new CueAnalysisAgent(sourceLanguage: $language)] as $agent) {
                $instructions = $agent->instructions();
                $this->assertStringContainsString(SubtitlePromptRules::segmentation($language), $instructions);
                $this->assertStringContainsString($example, $instructions);
                $this->assertStringContainsString('exactly once, in order', $instructions);
                $this->assertStringContainsString('exact contiguous substring', $instructions);
                $this->assertStringContainsString('Preserve source casing', $instructions);
            }
        }

        $english = (new CueTokenizationAgent('eng'))->instructions();
        $this->assertStringNotContainsString('Japanese examples:', $english);
        $this->assertStringNotContainsString('Chinese examples:', $english);
        $this->assertStringNotContainsString('Thai example:', $english);
        $this->assertSame(SubtitlePromptRules::segmentation('ja'), SubtitlePromptRules::segmentation('jpn'));
    }

    public function test_analysis_instructions_only_include_requested_operations(): void
    {
        $tokensOnly = (new CueAnalysisAgent(false, false, 'jpn'))->instructions();
        $translated = (new CueAnalysisAgent(true, false, 'jpn'))->instructions();
        $romanized = (new CueAnalysisAgent(false, true, 'jpn'))->instructions();

        $this->assertStringNotContainsString(SubtitlePromptRules::TRANSLATION, $tokensOnly);
        $this->assertStringContainsString(SubtitlePromptRules::TRANSLATION, $translated);
        $this->assertStringNotContainsString('modified Hepburn', $translated);
        $this->assertStringContainsString('modified Hepburn', $romanized);
        $this->assertStringContainsString('copy their text into the required romanization field', $romanized);
        $this->assertStringContainsString('contextCues', $translated);
        $this->assertStringNotContainsString('previousCueText', $translated);
    }

    public function test_subtitle_agents_treat_text_as_data_and_share_pronunciation_standards(): void
    {
        foreach ([new CueTokenizationAgent, new CueAnalysisAgent, new CueRomanizationAgent, new CueEnrichmentAgent, new LearningTokenCardAgent, new EditedCueAgent] as $agent) {
            $this->assertStringContainsString(SubtitlePromptRules::TEXT_IS_DATA, $agent->instructions());
        }

        foreach (['jpn' => 'macrons', 'cmn' => 'tone marks', 'yue' => 'Jyutping', 'kor' => 'Revised Romanization'] as $language => $convention) {
            foreach ([new CueAnalysisAgent(false, true, $language), new CueRomanizationAgent($language), new CueEnrichmentAgent($language), new LearningTokenCardAgent($language), new EditedCueAgent($language)] as $agent) {
                $this->assertStringContainsString($convention, $agent->instructions());
                $this->assertStringContainsString(SubtitlePromptRules::romanization($language), $agent->instructions());
            }
        }
    }

    public function test_card_agents_share_meaning_requirements_and_keep_edit_rules_separate(): void
    {
        foreach ([new CueEnrichmentAgent, new LearningTokenCardAgent, new EditedCueAgent] as $agent) {
            $this->assertStringContainsString(SubtitlePromptRules::WORD_CARD, $agent->instructions());
            $this->assertStringContainsString('Every token must have a non-empty translation or gloss.', $agent->instructions());
            $this->assertStringContainsString('Return null for unused metadata fields.', $agent->instructions());
        }

        $this->assertStringContainsString('Copy requestedToken.index exactly; do not renumber it to zero.', (new LearningTokenCardAgent)->instructions());
        $this->assertStringContainsString('preserve supplied non-empty readings exactly', (new CueEnrichmentAgent)->instructions());
        $this->assertStringContainsString('regenerate fresh readings', (new EditedCueAgent)->instructions());
        $this->assertStringContainsString('a replacement phrase stays one token', (new EditedCueAgent)->instructions());
    }

    public function test_compact_card_schemas_keep_identity_and_nullable_meanings_without_echoing_source(): void
    {
        $factory = new JsonSchemaTypeFactory;

        foreach ([new CueEnrichmentAgent, new EditedCueAgent] as $agent) {
            $schema = $agent->schema($factory);
            $cue = $schema['cues']->toArray()['items'];
            $this->assertArrayNotHasKey('sourceText', $cue['properties']);
            $this->assertContains('cueId', $cue['required']);
            $this->assertContains('index', $cue['required']);
            $this->assertFalse($cue['additionalProperties']);
            $token = $cue['properties']['tokens']['items'];
            $this->assertArrayNotHasKey('text', $token['properties']);
            $this->assertContains('index', $token['required']);
            $this->assertSame(['string', 'null'], $token['properties']['translation']['type']);
            $this->assertSame(['string', 'null'], $token['properties']['gloss']['type']);
            $this->assertFalse($token['additionalProperties']);
        }

        $token = (new LearningTokenCardAgent)->schema($factory)['token']->toArray();
        $this->assertArrayNotHasKey('text', $token['properties']);
        $this->assertContains('index', $token['required']);
        $this->assertSame(0, $token['properties']['index']['minimum']);
        $this->assertFalse($token['additionalProperties']);
    }
}
