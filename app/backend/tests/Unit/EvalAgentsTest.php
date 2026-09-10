<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\Agents\LyricsAlignmentAgent;
use App\Console\Commands\EvalAgents;
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
        foreach ([CueTokenizationAgent::class, CueAnalysisAgent::class, CueRomanizationAgent::class, CueEnrichmentAgent::class, EditedCueAgent::class, LearningTokenCardAgent::class] as $agent) {
            $agent::fake(fn (string $prompt): StructuredTextResponse => $this->response($this->agentOutput($agent, json_decode($prompt, true))))->preventStrayPrompts();
        }
        LyricsAlignmentAgent::fake(fn (string $prompt): array => json_decode($prompt, true)['allowPartial']
            ? $this->partialLyricsOutput()
            : (count(json_decode($prompt, true)['lyricsParts']) === 4
            ? ['isMatch' => true, 'isComplete' => false, 'cues' => []]
            : ['isMatch' => true, 'isComplete' => true, 'cues' => [
                ['cueId' => 'heldout-song-a', 'segments' => [['source' => 'pasted', 'endPartIndex' => 3]]],
                ['cueId' => 'heldout-song-b', 'segments' => [['source' => 'pasted', 'endPartIndex' => 9]]],
            ]]))->preventStrayPrompts();

        $report = $this->runReport(['--repeat' => '2']);
        $this->assertCount(9, $report['cases']);
        foreach ($report['cases'] as $case) {
            $this->assertSame('pending', $case['humanReview']['status']);
            $this->assertNull($case['humanReview']['score']);
            $this->assertSame(2, $case['latencyMs']['samples']);
            $this->assertGreaterThanOrEqual($case['latencyMs']['median'], $case['latencyMs']['p95']);
            foreach ($case['runs'] as $run) {
                $this->assertTrue($run['pipelineCompleted'], json_encode($run['pipelineError']));
                $this->assertSame(1, $run['requestCount']);
                $this->assertTrue($run['firstResponseContractChecksPassed'], json_encode($run['attempts'][0]['contractErrors']));
                $this->assertArrayNotHasKey('review', $run['attempts'][0]['input']);
                $this->assertArrayNotHasKey('goldTokens', $run['attempts'][0]['input']);
                if ($case['agent'] !== 'lyrics') {
                    $this->assertSame(123, $run['attempts'][0]['usage']['prompt_tokens']);
                    $this->assertSame(45, $run['attempts'][0]['usage']['completion_tokens']);
                }
                if ($case['agent'] === 'analysis') {
                    $this->assertCount(2, $run['attempts'][0]['input']['cues']);
                    $this->assertTrue($run['attempts'][0]['input']['includeTranslation']);
                    $this->assertTrue($run['attempts'][0]['input']['includeRomanization']);
                    $this->assertCount(2, $run['attempts'][0]['segmentation']);
                    $this->assertArrayHasKey('translated', $run['pipelineOutput']);
                    $this->assertArrayHasKey('romanized', $run['pipelineOutput']);
                }
                if ($case['agent'] === 'card') {
                    $this->assertSame(4, $run['attempts'][0]['input']['requestedToken']['index']);
                }
            }
        }
    }

    public function test_keeps_rejected_first_response_separate_from_split_retry_results(): void
    {
        $calls = 0;
        CueTokenizationAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;

            return $calls === 1 ? ['dialect' => 'unknown', 'cues' => []] : $this->agentOutput(CueTokenizationAgent::class, json_decode($prompt, true));
        })->preventStrayPrompts();
        $run = $this->runReport(['--agent' => 'tokenization'])['cases'][0]['runs'][0];
        $this->assertSame(3, $run['requestCount']);
        $this->assertFalse($run['firstResponseContractChecksPassed']);
        $this->assertTrue($run['pipelineCompleted']);
        $this->assertTrue($run['recoveredAfterInvalidFirstResponse']);
        $this->assertSame([], $run['attempts'][0]['output']['cues']);
        $this->assertCount(2, $run['pipelineOutput']['cues']);
    }

    public function test_records_failed_requests_without_exposing_exception_messages_or_inventing_usage(): void
    {
        LearningTokenCardAgent::fake(function (): never {
            throw new RuntimeException('secret-provider-key');
        })->preventStrayPrompts();
        $report = $this->runReport(['--agent' => 'card']);
        $run = $report['cases'][0]['runs'][0];
        $this->assertFalse($run['pipelineCompleted']);
        $this->assertSame(1, $run['requestCount']);
        $this->assertFalse($run['attempts'][0]['responseReceived']);
        $this->assertNull($run['attempts'][0]['usage']);
        $this->assertStringNotContainsString('secret-provider-key', json_encode($report));
    }

    public function test_tolerant_romanization_pipeline_does_not_turn_missing_readings_into_a_valid_first_response(): void
    {
        CueRomanizationAgent::fake([['dialect' => 'unknown', 'cues' => []]])->preventStrayPrompts();
        $run = $this->runReport(['--agent' => 'romanization'])['cases'][0]['runs'][0];
        $this->assertTrue($run['pipelineCompleted']);
        $this->assertFalse($run['firstResponseContractChecksPassed']);
        $this->assertContains('missing_cue_romanization', $run['attempts'][0]['contractErrors']);
    }

    public function test_malformed_output_is_recorded_without_the_observer_changing_provider_behavior(): void
    {
        CueRomanizationAgent::fake([['dialect' => 'unknown', 'cues' => 'malformed']])->preventStrayPrompts();
        $run = $this->runReport(['--agent' => 'romanization'])['cases'][0]['runs'][0];
        $this->assertTrue($run['pipelineCompleted']);
        $this->assertSame('malformed', $run['attempts'][0]['output']['cues']);
        $this->assertSame(['malformed_output'], $run['attempts'][0]['contractErrors']);
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
        $this->assertCount(7, array_unique($agents));
    }

    public function test_rejects_generated_latin_readings_in_raw_card_enrichment_and_edit_responses(): void
    {
        foreach (['card' => LearningTokenCardAgent::class, 'enrichment' => CueEnrichmentAgent::class, 'edited' => EditedCueAgent::class] as $name => $agent) {
            $agent::fake(function (string $prompt) use ($agent): array {
                $output = $this->agentOutput($agent, json_decode($prompt, true));
                if ($agent === LearningTokenCardAgent::class) {
                    $output['token']['romanization'] = 'abandonner';
                } else {
                    $output['cues'][0]['romanization'] = 'translation pronunciation';
                    $output['cues'][0]['tokens'][0]['romanization'] = 'translation pronunciation';
                }

                return $output;
            })->preventStrayPrompts();
            $run = $this->runReport(['--agent' => $name])['cases'][0]['runs'][0];
            $this->assertFalse($run['firstResponseContractChecksPassed']);
            $this->assertContains('unexpected_latin_token_romanization', $run['attempts'][0]['contractErrors']);
        }
    }

    public function test_reading_checks_respect_supplied_readings_disabled_flags_and_non_latin_source(): void
    {
        $check = new \ReflectionMethod(EvalAgents::class, 'readingErrors');
        $command = new EvalAgents;
        $latin = ['text' => 'bonjour', 'romanization' => 'bohn-zhoor'];
        $this->assertSame([], $check->invoke($command, $latin, ['romanization' => 'bohn-zhoor'], true, true, 'token'));
        $this->assertSame(['changed_token_romanization'], $check->invoke($command, $latin, ['romanization' => null], true, true, 'token'));
        $this->assertSame(['changed_token_romanization'], $check->invoke($command, $latin, ['romanization' => 'different'], true, true, 'token'));
        $this->assertSame([], $check->invoke($command, $latin, ['romanization' => null], false, true, 'token'));
        $this->assertSame(['unexpected_token_romanization'], $check->invoke($command, $latin, ['romanization' => 'bohn-zhoor'], false, true, 'token'));
        $this->assertSame(['missing_token_romanization'], $check->invoke($command, ['text' => '京都'], ['romanization' => null], true, false, 'token'));
        $this->assertSame([], $check->invoke($command, ['text' => '京都'], ['romanization' => 'Kyōto'], true, false, 'token'));
        $this->assertSame(['unexpected_latin_token_romanization'], $check->invoke($command, $latin, ['romanization' => 'bohn-zhoor'], true, false, 'token'));
    }

    public function test_partial_lyrics_accept_existing_segments_and_reject_dropped_or_overlapping_spans(): void
    {
        $valid = $this->partialLyricsOutput();
        $invalid = $valid;
        $invalid['cues'][1]['segments'][0]['endPartIndex'] = 4;
        $overlap = $valid;
        $overlap['cues'][0]['segments'][] = ['source' => 'pasted', 'startPartIndex' => 4, 'endPartIndex' => 4, 'separator' => ''];
        LyricsAlignmentAgent::fake([$valid, $invalid, $overlap])->preventStrayPrompts();
        $runs = $this->runReport(['--case' => 'heldout-lyrics-partial-enabled', '--repeat' => '3'])['cases'][0]['runs'];
        $this->assertTrue($runs[0]['firstResponseContractChecksPassed']);
        $this->assertContains('unpreserved_existing_parts', $runs[1]['attempts'][0]['contractErrors']);
        $this->assertContains('invalid_pasted_part_order', $runs[2]['attempts'][0]['contractErrors']);
    }

    private function partialLyricsOutput(): array
    {
        return ['isMatch' => true, 'isComplete' => false, 'cues' => [
            ['cueId' => 'heldout-partial-a', 'segments' => [['source' => 'pasted', 'startPartIndex' => 0, 'endPartIndex' => 4, 'separator' => '']]],
            ['cueId' => 'heldout-partial-b', 'segments' => [['source' => 'existing', 'startPartIndex' => 0, 'endPartIndex' => 5, 'separator' => '']]],
        ]];
    }

    private function runReport(array $options): array
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.key' => 'test-only']);
        $this->assertSame(0, Artisan::call('subtitles:eval-agents', $options));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function agentOutput(string $agent, array $input): array
    {
        if ($agent === LearningTokenCardAgent::class) {
            return ['token' => ['index' => $input['requestedToken']['index'], 'translation' => 'abandonner', 'gloss' => null]];
        }
        $gold = [];
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/agents/held-out.json')), true) as $case) {
            $gold += $case['goldTokens'] ?? [];
        }
        $cues = [];
        foreach ($input['cues'] as $cue) {
            $tokens = [];
            $texts = $gold[$cue['cueId']] ?? array_column($cue['tokens'], 'text');
            foreach ($texts as $index => $text) {
                $tokens[] = ['index' => $index, ...in_array($agent, [CueTokenizationAgent::class, CueAnalysisAgent::class], true) ? ['text' => $text] : [],
                    'translation' => 'fixture meaning', 'gloss' => 'fixture gloss', 'romanization' => preg_match('/[^\p{Latin}\P{L}]/u', $text) ? 'fixture reading' : null];
            }
            $cues[] = ['cueId' => $cue['cueId'], 'index' => $cue['index'], 'translatedText' => 'fixture sentence', 'romanization' => preg_match('/[^\p{Latin}\P{L}]/u', $cue['sourceText']) ? 'fixture reading' : null, 'tokens' => $tokens];
        }

        return ['dialect' => 'unknown', 'cues' => $cues, 'translatedText' => 'fixture edited translation'];
    }

    private function response(array $output): StructuredTextResponse
    {
        return new StructuredTextResponse($output, json_encode($output), new Usage(promptTokens: 123, completionTokens: 45), new Meta(provider: 'openai', model: 'fake'));
    }
}
