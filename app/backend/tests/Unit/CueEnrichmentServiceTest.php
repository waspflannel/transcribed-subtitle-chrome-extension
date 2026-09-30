<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\PromptingAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CueEnrichmentServiceTest extends TestCase
{
    private int $promptCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen(PromptingAgent::class, function (): void {
            $this->promptCount++;
        });
    }

    #[TestWith(['openai', 'cerebras'])]
    #[TestWith(['cerebras', 'openai'])]
    public function test_explicit_selection_routes_every_text_operation_without_changing_the_default(string $provider, string $default): void
    {
        config(['ai.default' => $default, "ai.providers.{$provider}.models.text.default" => 'different-current-model']);
        $selection = new SubtitleModel($provider, 'saved-model');
        $cue = [...$this->part(0, 'Hello'), 'translatedText' => 'Hello', 'tokens' => [['index' => 0, 'text' => 'Hello']]];
        $cards = ['dialect' => 'unknown', 'cues' => [['cueId' => 'cue-0', 'index' => 0, 'tokens' => [['index' => 0, 'translation' => 'Hola']]]]];
        CueAnalysisAgent::fake([['cues' => [$cue]]])->preventStrayPrompts();
        LearningTokenCardAgent::fake([['token' => ['index' => 0, 'translation' => 'Hola']]])->preventStrayPrompts();
        EditedCueAgent::fake([$cards])->preventStrayPrompts();
        $analysis = app(LaravelAiTranslationAnalysisProvider::class);
        $analysis->analyzeCueBatch([$cue], [$cue], 'eng', 'spa', false, false, selection: $selection);
        $analysis->enrichToken($cue, $cue['tokens'][0], 'eng', 'spa', $selection);
        $analysis->refreshEditedCue($cue, 'eng', 'spa', false, false, $selection);
        foreach ([CueAnalysisAgent::class, LearningTokenCardAgent::class, EditedCueAgent::class] as $agent) {
            $agent::assertPrompted(fn ($prompt): bool => $prompt->provider->name() === $provider && $prompt->model === 'saved-model');
        }
        $this->assertSame($default, config('ai.default'));
        $this->assertSame('different-current-model', config("ai.providers.{$provider}.models.text.default"));
    }

    public function test_unchecked_analysis_preserves_source_cues_and_accepts_model_bookkeeping_errors(): void
    {
        $parts = [$this->part(10, 'First', 1000, 2000), $this->part(11, 'Second', 2000, 3000), $this->part(12, 'Third', 3000, 4000)];
        CueAnalysisAgent::fake([['cues' => [
            ['cueId' => 'cue-11', 'index' => 0, 'translatedText' => 'Second translation', 'tokens' => [['index' => 99, 'text' => 'Second'], null]],
            ['cueId' => 'wrong-id', 'index' => 1, 'translatedText' => 'First translation', 'tokens' => [['index' => -1, 'text' => 'First'], ['text' => '!']]],
        ]]])->preventStrayPrompts();
        $result = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch($parts, $parts, 'eng', 'spa', includeRomanization: true, validateOutput: false);
        $this->assertSame(['First translation', 'Second translation', 'Third'], array_column($result->cues, 'translatedText'));
        foreach ($result->cues as $i => $cue) {
            $this->assertSame($parts[$i], array_intersect_key($cue, $parts[$i]));
            $this->assertArrayNotHasKey('romanization', $cue);
        }
        $this->assertSame([['index' => 0, 'text' => 'First', 'normalizedText' => 'first']], $result->cues[0]['tokens']);
        $this->assertSame([['index' => 0, 'text' => 'Second', 'normalizedText' => 'second']], $result->cues[1]['tokens']);
        $this->assertSame([], $result->cues[2]['tokens']);
    }

    #[TestWith([[]])]
    #[TestWith([['cues' => [null, ['cueId' => 'extra', 'tokens' => 'malformed']]]])]
    public function test_unchecked_analysis_keeps_aligned_text_when_details_are_missing(array $output): void
    {
        $parts = [$this->part(0, 'Keep these lyrics')];
        CueAnalysisAgent::fake([$output])->preventStrayPrompts();
        $result = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch($parts, $parts, 'eng', 'spa', includeRomanization: true, validateOutput: false);
        $this->assertCount(1, $result->cues);
        $this->assertSame('Keep these lyrics', $result->cues[0]['translatedText']);
        $this->assertSame([], $result->cues[0]['tokens']);
    }

    public function test_luna_annotates_fixed_cues_and_returns_all_requested_language_work_once(): void
    {
        $parts = [$this->part(10, 'Hello world.', 1000, 2000)];
        $output = $this->analysisOutput(10, 'Hello world.');
        $output['cues'][0]['tokens'] = [['index' => 0, 'text' => 'Hello', 'romanization' => 'Hello'], ['index' => 1, 'text' => 'world', 'romanization' => 'world']];
        CueAnalysisAgent::fake([$output])->preventStrayPrompts();
        $result = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch(
            $parts, [$this->part(9, 'Before'), ...$parts, $this->part(12, 'After')], 'eng', 'spa', includeRomanization: true);
        $cue = $result->cues[0];
        $this->assertSame('cue-10', $cue['cueId']);
        $this->assertSame(1000, $cue['startMs']);
        $this->assertSame(2000, $cue['endMs']);
        $this->assertSame('Hello world.', $cue['sourceText']);
        $this->assertSame('Meaning', $cue['translatedText']);
        $this->assertSame('Reading', $cue['romanization']);
        CueAnalysisAgent::assertPrompted(function ($prompt): bool {
            $input = json_decode($prompt->prompt, true);

            return array_column($input['cues'], 'index') === [10]
                && array_column($input['contextCues'], 'sourceText') === ['Before', 'After']
                && $input['cues'][0]['sourceText'] === 'Hello world.' && $input['includeTranslation'] && $input['includeRomanization'];
        });
        $this->assertSame(1, $this->promptCount);
    }

    public function test_captured_analysis_with_missing_source_spaces_keeps_tokens_and_readings_without_repair(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/agents/source-spacing-response.json')), true, 512, JSON_THROW_ON_ERROR);
        $cues = $fixture['sourceCues'];
        CueAnalysisAgent::fake([$fixture['response']])->preventStrayPrompts();

        $result = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch($cues, $cues, 'ara', 'eng', true, true);

        foreach ($result->cues as $position => $cue) {
            $source = $cues[$position];
            $generated = $fixture['response']['cues'][$position];
            foreach (['cueId', 'index', 'sourceText', 'startMs', 'endMs'] as $field) {
                $this->assertSame($source[$field], $cue[$field]);
            }
            $this->assertSame($generated['translatedText'], $cue['translatedText']);
            $this->assertSame($generated['romanization'], $cue['romanization']);
            $this->assertSame(array_column($generated['tokens'], 'text'), array_column($cue['tokens'], 'text'));
            $this->assertSame(array_column($generated['tokens'], 'romanization'), array_column($cue['tokens'], 'romanization'));
        }
        $this->assertCount(2, $result->cues);
        $this->assertSame(1, $this->promptCount);
    }

    public function test_disabled_features_need_no_translation_or_readings(): void
    {
        $part = $this->part(0, 'Hello');
        CueAnalysisAgent::fake([['dialect' => 'unknown', 'cues' => [['cueId' => 'cue-0', 'index' => 0, 'tokens' => [['index' => 0, 'text' => 'Hello']]]]]])->preventStrayPrompts();
        $cue = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$part], [$part], 'eng', 'spa', false, false)->cues[0];
        $this->assertSame('Hello', $cue['translatedText']);
        $this->assertArrayNotHasKey('romanization', $cue);
        $this->assertSame(1, $this->promptCount);
    }

    #[DataProvider('invalidOutputs')]
    public function test_analysis_validation_retains_main_acceptance_rules(array $output, bool $accepted): void
    {
        $source = [$this->part(0, 'Hello')];
        try {
            $result = app(LaravelAiTranslationAnalysisProvider::class)->validatedAnalysis($output, $source, true, true);
            $this->assertTrue($accepted);
            $this->assertSame('Hello', $result->cues[0]['sourceText']);
        } catch (SubtitleProcessingException $exception) {
            $this->assertFalse($accepted);
            $this->assertSame('enrichment_failed', $exception->publicCode);
        }
    }

    public static function invalidOutputs(): array
    {
        $cue = ['cueId' => 'cue-0', 'index' => 0, 'translatedText' => 'Meaning', 'romanization' => 'Reading', 'tokens' => [['index' => 0, 'text' => 'Hello', 'romanization' => 'Hello']]];
        $cases = ['missing cues' => ['dialect' => 'unknown', 'cues' => []]];
        foreach (['index' => 8, 'translatedText' => '', 'romanization' => null, 'tokens' => []] as $key => $value) {
            $cases[$key] = ['dialect' => 'unknown', 'cues' => [array_replace($cue, [$key => $value])]];
        }
        foreach (['missing reading' => ['index' => 0, 'text' => 'Hello'], 'rewritten source' => ['index' => 0, 'text' => 'Goodbye', 'romanization' => 'Goodbye'], 'changed index' => ['index' => 3, 'text' => 'Hello', 'romanization' => 'Hello']] as $key => $token) {
            $cases[$key] = ['dialect' => 'unknown', 'cues' => [array_replace($cue, ['tokens' => [$token]])]];
        }
        $cases['repeated range'] = ['dialect' => 'unknown', 'cues' => [$cue, $cue]];

        return array_map(fn (array $output, string $case): array => [$output, in_array($case, ['rewritten source'], true)], $cases, array_keys($cases));
    }

    public function test_main_analysis_ignores_punctuation_only_tokens(): void
    {
        $part = $this->part(0, 'Hello, world');
        $output = $this->analysisOutput(0, 'Hello, world');
        $output['cues'][0]['tokens'] = [['index' => 0, 'text' => 'Hello'], ['index' => 1, 'text' => ','], ['index' => 2, 'text' => 'world']];
        $result = app(LaravelAiTranslationAnalysisProvider::class)->validatedAnalysis($output, [$part], true, false);
        $this->assertSame(['Hello', 'world'], array_column($result->cues[0]['tokens'], 'text'));
    }

    public function test_canonical_cue_text_preserves_no_space_words(): void
    {
        $parts = [$this->part(0, '学 习 中文', 0, 500)];
        $output = $this->analysisOutput(0, '学习中文');
        $output['cues'][0]['tokens'] = [['index' => 0, 'text' => '学习'], ['index' => 1, 'text' => '中文']];
        CueAnalysisAgent::fake([$output]);
        $cue = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch($parts, $parts, 'cmn', 'eng')->cues[0];
        $this->assertSame('学 习 中文', $cue['sourceText']);
        $this->assertSame(['学习', '中文'], array_column($cue['tokens'], 'text'));
    }

    public function test_cues_cannot_be_merged(): void
    {
        $parts = [$this->part(0, 'Hello', 0, 100), $this->part(1, 'world', 100, 200)];
        $this->expectException(SubtitleProcessingException::class);
        app(LaravelAiTranslationAnalysisProvider::class)->validatedAnalysis($this->analysisOutput(1, 'Hello world'), $parts, true, false);
    }

    public function test_analysis_must_cover_the_last_source_cue(): void
    {
        $parts = [$this->part(0, 'Hello', 0, 100), $this->part(1, 'world', 100, 200)];
        $this->expectException(SubtitleProcessingException::class);
        app(LaravelAiTranslationAnalysisProvider::class)->validatedAnalysis($this->analysisOutput(0, 'Hello'), $parts, true, false);
    }

    public function test_mixed_script_transcription_corrections_are_trusted(): void
    {
        $part = $this->part(11, 'حلفوا غصن يديנו النجسة.');
        $output = $this->analysisOutput(11, 'يدينو');
        CueAnalysisAgent::fake([$output])->preventStrayPrompts();
        $cue = app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$part], [$part], 'ara', 'eng', beforeRetry: fn () => $this->fail('Valid corrections need no retry.'))->cues[0];
        $this->assertSame($part['sourceText'], $cue['sourceText']);
        $this->assertSame('يدينو', $cue['tokens'][0]['text']);
        $this->assertSame(1, $this->promptCount);
    }

    public function test_word_card_accepts_an_honest_gloss_without_inventing_a_translation(): void
    {
        LearningTokenCardAgent::fake([['token' => ['index' => 3, 'gloss' => 'Unclear vocalization']]]);
        $token = app(LaravelAiTranslationAnalysisProvider::class)->enrichToken(['sourceText' => 'la', 'translatedText' => 'la'], ['index' => 3, 'text' => 'la'], 'eng', 'spa');
        $this->assertSame(3, $token['index']);
        $this->assertSame('Unclear vocalization', $token['gloss']);
        $this->assertArrayNotHasKey('translation', $token);
    }

    public function test_word_card_rejects_missing_meaning(): void
    {
        LearningTokenCardAgent::fake([['token' => ['index' => 0, 'translation' => null, 'gloss' => null]]])->preventStrayPrompts();
        $this->expectException(SubtitleProcessingException::class);
        app(LaravelAiTranslationAnalysisProvider::class)->enrichToken(['sourceText' => 'Hello', 'translatedText' => 'Hola'], ['index' => 0, 'text' => 'Hello'], 'eng', 'spa');
    }

    #[DataProvider('httpErrors')]
    public function test_transport_errors_keep_the_actual_provider_and_do_not_retry_content(int $status, string $code): void
    {
        config(['ai.providers.openai.key' => 'test-key', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Unavailable']], $status)]);
        $part = $this->part(0, 'Hello');
        try {
            app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$part], [$part], 'eng', 'spa', beforeRetry: fn () => $this->fail('Transport and output-limit errors must bypass content retry.'));
            $this->fail('Expected a provider error.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame($code, $exception->publicCode);
            $this->assertSame('openai', $exception->context['provider']);
        }
        Http::assertSentCount(1);
    }

    public static function httpErrors(): array
    {
        return [[400, 'enrichment_failed'], [429, 'rate_limited'], [500, 'provider_unavailable']];
    }

    public function test_output_exhaustion_fails_without_using_partial_json(): void
    {
        config(['ai.providers.openai.key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([
            'id' => 'resp_limit', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
            'model' => 'gpt-6-luna', 'output' => [], 'usage' => ['input_tokens' => 2000, 'output_tokens' => 9000],
        ])]);
        $part = $this->part(0, 'Hello');
        try {
            app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$part], [$part], 'eng', 'spa', beforeRetry: fn () => $this->fail('Transport and output-limit errors must bypass content retry.'));
            $this->fail('Truncated output must fail.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('output_token_limit', $exception->context['reason']);
            $this->assertFalse($exception->isTransient());
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('quotaErrors')]
    public function test_sdk_wrapped_quota_errors_are_terminal(string $code, string $type): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => [
            'code' => $code, 'type' => $type, 'message' => 'private-provider-message',
        ]], 429)]);

        try {
            $part = $this->part(0, 'Hello');
            app(LaravelAiTranslationAnalysisProvider::class)->analyzeCueBatch([$part], [$part], 'eng', 'spa', beforeRetry: fn () => $this->fail('Transport and output-limit errors must bypass content retry.'));
            $this->fail('Quota exhaustion must fail the batch.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_quota_exhausted', $exception->context['reason'] ?? null);
            $this->assertFalse($exception->isTransient());
            $this->assertStringNotContainsString('private-provider-message', $exception->getMessage().json_encode($exception->context));
        }

        Http::assertSentCount(1);
    }

    public static function quotaErrors(): array
    {
        return [
            ['credit_balance_exhausted', 'billing_error'],
            ['insufficient_quota', 'billing_error'],
            ['organization_spend_limit_exceeded', 'billing_error'],
            ['project_spend_limit_exceeded', 'billing_error'],
            ['organization_usage_limit_exceeded', 'billing_error'],
            ['unknown_billing_code', 'insufficient_quota'],
        ];
    }

    private function part(int $index, string $text, int $start = 0, int $end = 1000): array
    {
        return ['cueId' => 'cue-'.$index, 'index' => $index, 'sourceText' => $text, 'startMs' => $start, 'endMs' => $end];
    }

    private function analysisOutput(int $end, string $text): array
    {
        return ['dialect' => 'unknown', 'cues' => [['cueId' => 'cue-'.$end, 'index' => $end, 'translatedText' => 'Meaning', 'romanization' => 'Reading', 'tokens' => [['index' => 0, 'text' => $text, 'romanization' => 'Reading']]]]];
    }
}
