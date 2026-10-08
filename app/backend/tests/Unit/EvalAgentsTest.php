<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use RuntimeException;
use Tests\TestCase;

class EvalAgentsTest extends TestCase
{
    public function test_reports_all_agents_real_batches_usage_and_pending_semantic_review(): void
    {
        Http::preventStrayRequests();
        foreach ([CueAnalysisAgent::class, EditedCueAgent::class, LearningTokenCardAgent::class] as $agent) {
            $agent::fake(fn (string $prompt): StructuredTextResponse => $this->response($this->agentOutput($agent, json_decode($prompt, true))))->preventStrayPrompts();
        }
        LyricsAlignmentAgent::fake(function (string $prompt): array {
            $input = json_decode($prompt, true);

            return ['cues' => [['cueId' => $input['cues'][0]['cueId'], 'endPartIndex' => count($input['lyricsParts']) - 1]]];
        })->preventStrayPrompts();

        $report = $this->runReport(['--repeat' => '2']);
        $this->assertCount(8, $report['cases']);
        foreach ($report['cases'] as $case) {
            $this->assertSame('pending', $case['humanReview']['status']);
            $this->assertNull($case['humanReview']['score']);
            $this->assertSame(2, $case['latencyMs']['samples']);
            $this->assertGreaterThanOrEqual($case['latencyMs']['median'], $case['latencyMs']['p95']);
            foreach ($case['runs'] as $run) {
                $this->assertTrue($run['pipelineCompleted'], json_encode($run['pipelineError']));
                $this->assertSame(1, $run['requestCount']);
                $this->assertSame($case['agent'] === 'lyrics' ? null : true, $run['firstResponseContractChecksPassed'], json_encode($run['attempts'][0]['contractErrors']));
                $this->assertArrayNotHasKey('review', $run['attempts'][0]['input']);
                $this->assertArrayNotHasKey('goldTokens', $run['attempts'][0]['input']);
                if ($case['agent'] !== 'lyrics') {
                    $this->assertSame(123, $run['attempts'][0]['usage']['prompt_tokens']);
                    $this->assertSame(45, $run['attempts'][0]['usage']['completion_tokens']);
                }
                if ($case['agent'] === 'analysis') {
                    $this->assertArrayHasKey('cues', $run['attempts'][0]['input']);
                    $this->assertArrayHasKey('cues', $run['pipelineOutput']);
                }
                if ($case['agent'] === 'card') {
                    $this->assertSame(4, $run['attempts'][0]['input']['requestedToken']['index']);
                }
            }
        }
    }

    public function test_model_override_is_global_and_restored_after_evaluation(): void
    {
        $original = config('ai.providers.openai.models.text.default');
        LearningTokenCardAgent::fake([['token' => ['index' => 4, 'gloss' => 'A contextual meaning']]])->preventStrayPrompts();
        $report = $this->runReport(['--agent' => 'card', '--model' => 'one-model']);
        $this->assertSame('one-model', $report['model']);
        $this->assertSame('openai', $report['provider']);
        $this->assertSame($original, config('ai.providers.openai.models.text.default'));
    }

    public function test_eval_records_invalid_first_response_without_recovery(): void
    {
        CueAnalysisAgent::fake([['dialect' => 'unknown', 'cues' => []]])->preventStrayPrompts();
        $report = $this->runReport(['--case' => 'heldout-analysis-japanese-french'], 1);
        $run = $report['cases'][0]['runs'][0];
        $this->assertFalse($run['pipelineCompleted']);
        $this->assertFalse($run['firstResponseContractChecksPassed']);
        $this->assertSame(1, $run['requestCount']);
        $this->assertContains('cue_count_mismatch', $run['attempts'][0]['contractErrors']);
    }

    public function test_records_failed_requests_without_exposing_exception_messages_or_inventing_usage(): void
    {
        LearningTokenCardAgent::fake(function (): never {
            throw new RuntimeException('secret-provider-key');
        })->preventStrayPrompts();
        $report = $this->runReport(['--agent' => 'card'], 1);
        $run = $report['cases'][0]['runs'][0];
        $this->assertFalse($run['pipelineCompleted']);
        $this->assertSame(1, $run['requestCount']);
        $this->assertFalse($run['attempts'][0]['responseReceived']);
        $this->assertNull($run['attempts'][0]['usage']);
        $this->assertStringNotContainsString('secret-provider-key', json_encode($report));
    }

    public function test_held_out_inputs_are_not_embedded_in_production_prompts(): void
    {
        $prompts = implode('\n', array_map('file_get_contents', [base_path('app/Ai/SubtitlePromptRules.php'), ...glob(base_path('app/Ai/Agents/*.php'))]));
        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/agents/held-out.json')), true);
        $agents = [];
        foreach ($fixtures as $case) {
            $agents[] = $case['agent'];
            $this->assertNotEmpty($case['review']);
            foreach ([...$case['cues'], ...($case['lyricsInput']['cues'] ?? [])] as $cue) {
                $this->assertStringNotContainsString($cue['sourceText'], $prompts, $case['id']);
            }
        }
        $this->assertCount(4, array_unique($agents));
    }

    private function runReport(array $options, int $status = 0): array
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.key' => 'test-only']);
        $exit = Artisan::call('subtitles:eval-agents', $options);
        $output = Artisan::output();
        $this->assertSame($status, $exit, $output);

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }

    private function agentOutput(string $agent, array $input): array
    {
        if ($agent === LearningTokenCardAgent::class) {
            return ['token' => ['index' => $input['requestedToken']['index'], 'translation' => 'abandonner', 'gloss' => null]];
        }
        $gold = [];
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/agents/held-out.json')), true) as $case) {
            foreach ($case['cues'] as $cue) {
                $gold[$cue['sourceText']] = $case['goldTokens'][$cue['cueId']] ?? array_column($cue['tokens'] ?? [], 'text');
            }
        }
        $cues = [];
        foreach ($input['cues'] as $cue) {
            $tokens = [];
            $texts = $agent === CueAnalysisAgent::class ? ($gold[$cue['sourceText']] ?: [$cue['sourceText']]) : array_column($cue['tokens'], 'text');
            foreach ($texts as $index => $text) {
                $tokens[] = ['index' => $index, ...($agent === CueAnalysisAgent::class ? ['text' => $text] : []),
                    'translation' => 'fixture meaning', 'gloss' => 'fixture gloss', 'romanization' => 'fixture reading'];
            }
            $cues[] = ['cueId' => $cue['cueId'], 'index' => $cue['index'], 'translatedText' => 'fixture sentence', 'romanization' => 'fixture reading', 'tokens' => $tokens];
        }

        return ['dialect' => 'unknown', 'cues' => $cues, 'translatedText' => 'fixture edited translation'];
    }

    private function response(array $output): StructuredTextResponse
    {
        return new StructuredTextResponse($output, json_encode($output), new Usage(promptTokens: 123, completionTokens: 45), new Meta(provider: 'openai', model: 'fake'));
    }
}
