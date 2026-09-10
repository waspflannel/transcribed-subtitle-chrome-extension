<?php

namespace App\Console\Commands;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use App\Services\TranslationAnalysis\TokenizationBoundaryMetric;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

#[Signature('subtitles:eval-agents {--agent=all : tokenization, analysis, romanization, enrichment, card, edited, lyrics, or all} {--case= : Run one held-out case ID} {--repeat=1 : Repetitions per case} {--model= : Override every task model} {--out= : Write the JSON review report to this path}')]
#[Description('Evaluate held-out agent cases with first-response evidence, pipeline outcomes, and pending human semantic review. Makes live calls unless agents are faked.')]
class EvalAgents extends Command
{
    private const AGENTS = ['tokenization', 'analysis', 'romanization', 'enrichment', 'card', 'edited', 'lyrics'];

    private ?array $activeCase = null;

    private array $attempts = [];

    private bool $listening = false;

    public function handle(LaravelAiTranslationAnalysisProvider $provider): int
    {
        $agent = (string) $this->option('agent');
        $repeat = filter_var($this->option('repeat'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if (($agent !== 'all' && ! in_array($agent, self::AGENTS, true)) || $repeat === false) {
            $this->components->error('Choose a supported agent and repeat count between 1 and 100.');

            return self::FAILURE;
        }

        $fixtures = json_decode(file_get_contents(base_path('tests/Fixtures/agents/held-out.json')), true, 512, JSON_THROW_ON_ERROR);
        $fixtures = array_values(array_filter($fixtures, fn (array $case): bool => ($agent === 'all' || $case['agent'] === $agent)
            && (! $this->option('case') || $case['id'] === $this->option('case'))));
        if ($fixtures === []) {
            $this->components->error('No matching held-out cases.');

            return self::FAILURE;
        }

        $this->listen();
        $originalModels = config('ai.providers.'.config('ai.default').'.models');
        if (trim((string) $this->option('model')) !== '') {
            foreach (['tokenization', 'analysis', 'romanization', 'enrichment'] as $purpose) {
                config(['ai.providers.'.config('ai.default').'.models.'.$purpose.'.default' => trim((string) $this->option('model'))]);
            }
        }

        try {
            $results = [];
            foreach ($fixtures as $case) {
                $runs = [];
                for ($run = 0; $run < $repeat; $run++) {
                    $runs[] = $this->runCase($provider, $case);
                }
                $results[] = [
                    'id' => $case['id'], 'agent' => $case['agent'], 'tags' => $case['tags'],
                    'humanReview' => ['status' => 'pending', 'expectations' => $case['review'], 'score' => null],
                    'latencyMs' => $this->latencySummary(array_column($runs, 'latencyMs')),
                    'firstResponseLatencyMs' => $this->latencySummary(array_values(array_filter(array_map(fn (array $run): ?float => $run['attempts'][0]['latencyMs'] ?? null, $runs), fn ($value): bool => $value !== null))),
                    'runs' => $runs,
                ];
            }
            $report = [
                'provider' => config('ai.default'), 'createdAt' => now()->toIso8601String(),
                'scope' => 'Six agents use production provider methods, including retries and fallbacks. Lyrics is agent-only; no lyrics pipeline validation is claimed.',
                'validityScope' => 'Contract checks cover identity, source coverage, required meanings, source-script reading rules, and lyrics span coverage/order. For partial lyrics, whether pasted spans replace the correct existing words still needs human review. These checks are not a general JSON Schema validator or a language-quality judge.',
                'usageScope' => 'SDK-reported prompt/completion tokens per captured structured response. A request failing before AgentPrompted has unknown usage and no captured response, even if the remote provider returned malformed data. Faked responses are not performance evidence.',
                'semanticQuality' => 'Pending bilingual human review; segmentation reference metrics are separate from semantic quality. Keep these held-out cases out of prompts.',
                'percentileMethod' => 'Median averages the two middle values; p95 uses nearest rank. Small samples are descriptive only.',
                'cases' => $results,
            ];
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($this->option('out')) {
                file_put_contents((string) $this->option('out'), $json.PHP_EOL);
            }
            $this->line($json);
        } finally {
            $this->activeCase = null;
            config(['ai.providers.'.config('ai.default').'.models' => $originalModels]);
        }

        return self::SUCCESS;
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;
        Event::listen(PromptingAgent::class, function (PromptingAgent $event): void {
            if ($this->activeCase === null) {
                return;
            }
            $this->attempts[$event->invocationId] = [
                'startedAt' => hrtime(true), 'agent' => class_basename($event->prompt->agent),
                'model' => $event->prompt->model, 'instructionsHash' => hash('sha256', (string) $event->prompt->agent->instructions()),
                'input' => json_decode($event->prompt->prompt, true, 512, JSON_THROW_ON_ERROR),
                'responseReceived' => false, 'output' => null, 'usage' => null, 'latencyMs' => null,
                'contractChecksPassed' => false, 'contractErrors' => ['no_response'], 'segmentation' => [],
            ];
        });
        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            if ($this->activeCase === null || ! isset($this->attempts[$event->invocationId])) {
                return;
            }
            $attempt = &$this->attempts[$event->invocationId];
            $attempt['latencyMs'] = (hrtime(true) - $attempt['startedAt']) / 1_000_000;
            $attempt['responseReceived'] = true;
            $attempt['output'] = $event->response->toArray();
            $attempt['usage'] = $event->response->usage->toArray();
            $attempt['finishReason'] = $event->response->steps->last()?->finishReason?->value;
            try {
                $attempt['contractErrors'] = $this->contractErrors($this->activeCase['agent'], $attempt['input'], $attempt['output']);
                $attempt['segmentation'] = $this->segmentation($this->activeCase, $attempt['output']);
            } catch (Throwable) {
                $attempt['contractErrors'] = ['malformed_output'];
            }
            if ($event->response->steps->last()?->finishReason === FinishReason::Length) {
                $attempt['contractErrors'][] = 'output_token_limit';
            }
            $attempt['contractChecksPassed'] = $attempt['contractErrors'] === [];
        });
    }

    private function runCase(LaravelAiTranslationAnalysisProvider $provider, array $case): array
    {
        $this->activeCase = $case;
        $this->attempts = [];
        $startedAt = hrtime(true);
        $pipeline = null;
        $error = null;
        try {
            $cues = $case['cues'];
            $context = [...($case['before'] ?? []), ...$cues, ...($case['after'] ?? [])];
            $source = $case['sourceLanguage'];
            $target = $case['targetLanguage'];
            $pipeline = match ($case['agent']) {
                'tokenization' => $provider->tokenizeCueBatch($cues, $context, $source),
                'analysis' => $provider->analyzeCueBatch($cues, $context, $source, $target, includeTranslation: true, includeRomanization: true),
                'romanization' => $provider->romanizeCueBatch($cues, $source),
                'enrichment' => $provider->enrichCueBatch($cues, $source, $target, true),
                'card' => $provider->enrichToken($cues[0], $cues[0]['tokens'][$case['requestedTokenPosition']], $source, $target),
                'edited' => $provider->refreshEditedCue($cues[0], $source, $target, true, true),
                'lyrics' => LyricsAlignmentAgent::make(allowPartial: $case['lyricsInput']['allowPartial'])
                    ->prompt(json_encode($case['lyricsInput'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->toArray(),
            };
        } catch (Throwable $exception) {
            $error = ['type' => $exception::class, 'reason' => $exception instanceof SubtitleProcessingException ? ($exception->context['reason'] ?? $exception->publicCode) : 'request_failed'];
        } finally {
            $this->activeCase = null;
        }
        $attempts = array_values($this->attempts);
        foreach ($attempts as &$attempt) {
            unset($attempt['startedAt']);
        }
        unset($attempt);

        return [
            'latencyMs' => (hrtime(true) - $startedAt) / 1_000_000, 'requestCount' => count($attempts),
            'firstResponseContractChecksPassed' => $attempts[0]['contractChecksPassed'] ?? null,
            'pipelineCompleted' => $error === null, 'pipelineOutput' => $pipeline, 'pipelineError' => $error,
            'recoveredAfterInvalidFirstResponse' => $error === null && isset($attempts[0]) && ! $attempts[0]['contractChecksPassed'],
            'attempts' => $attempts,
        ];
    }

    private function contractErrors(string $agent, array $input, array $output): array
    {
        $errors = [];
        if ($agent === 'lyrics') {
            return $this->lyricsErrors($input, $output);
        }
        if ($agent === 'card') {
            $token = $output['token'] ?? [];

            return [...array_values(array_filter([
                ($token['index'] ?? null) === $input['requestedToken']['index'] ? null : 'token_identity_mismatch',
                $this->nonempty($token['translation'] ?? null) || $this->nonempty($token['gloss'] ?? null) ? null : 'missing_token_meaning',
            ])), ...$this->readingErrors($input['requestedToken'], $token, true, true, 'token')];
        }
        if (count($output['cues'] ?? []) !== count($input['cues'])) {
            $errors[] = 'cue_count_mismatch';
        }
        foreach ($input['cues'] as $position => $cue) {
            $generated = $output['cues'][$position] ?? [];
            if (($generated['cueId'] ?? null) !== $cue['cueId'] || ($generated['index'] ?? null) !== $cue['index']) {
                $errors[] = 'cue_identity_mismatch';
            }
            if (in_array($agent, ['tokenization', 'analysis'], true)) {
                try {
                    app(LearningTokenOutputValidator::class)->validatedGeneratedTokens($generated['tokens'] ?? null, $cue['sourceText'], $cue['index']);
                } catch (SubtitleProcessingException $exception) {
                    $errors[] = $exception->context['reason'] ?? 'invalid_tokens';
                }
            } elseif (array_column($generated['tokens'] ?? [], 'index') !== array_column($cue['tokens'], 'index')) {
                $errors[] = 'token_identity_mismatch';
            }
            if ($agent === 'analysis' && ($input['includeTranslation'] ?? false) && ! $this->nonempty($generated['translatedText'] ?? null)) {
                $errors[] = 'missing_translation';
            }
            foreach ($generated['tokens'] ?? [] as $token) {
                if (in_array($agent, ['enrichment', 'edited'], true) && ! $this->nonempty($token['translation'] ?? null) && ! $this->nonempty($token['gloss'] ?? null)) {
                    $errors[] = 'missing_token_meaning';
                }
            }
            if (in_array($agent, ['enrichment', 'edited'], true)) {
                $enabled = $input['includeRomanization'] ?? false;
                $preserve = $agent === 'enrichment';
                $errors = [...$errors, ...$this->readingErrors($cue, $generated, $enabled, $preserve, 'cue')];
                foreach ($cue['tokens'] as $index => $token) {
                    $errors = [...$errors, ...$this->readingErrors($token, $generated['tokens'][$index] ?? [], $enabled, $preserve, 'token')];
                }

                continue;
            }
            $needsReading = $agent === 'romanization' || ($input['includeRomanization'] ?? false);
            if ($needsReading && preg_match('/[^\p{Latin}\P{L}]/u', $cue['sourceText']) === 1) {
                if (! $this->nonempty($generated['romanization'] ?? null)) {
                    $errors[] = 'missing_cue_romanization';
                }
                foreach ($generated['tokens'] ?? [] as $index => $token) {
                    $text = $token['text'] ?? $cue['tokens'][$index]['text'] ?? '';
                    if (preg_match('/[^\p{Latin}\P{L}]/u', $text) === 1 && ! $this->nonempty($token['romanization'] ?? null)) {
                        $errors[] = 'missing_token_romanization';
                    }
                }
            }
        }
        if ($agent === 'edited' && ! $this->nonempty($output['translatedText'] ?? null)) {
            $errors[] = 'missing_translation';
        }

        return array_values(array_unique($errors));
    }

    private function readingErrors(array $source, array $output, bool $enabled, bool $preserve, string $level): array
    {
        $reading = $output['romanization'] ?? null;
        if (! $enabled) {
            return $reading === null ? [] : ['unexpected_'.$level.'_romanization'];
        }
        if ($preserve && $this->nonempty($source['romanization'] ?? null)) {
            return $reading === $source['romanization'] ? [] : ['changed_'.$level.'_romanization'];
        }
        if (preg_match('/[^\p{Latin}\P{L}]/u', $source['sourceText'] ?? $source['text']) === 1) {
            return $this->nonempty($reading) ? [] : ['missing_'.$level.'_romanization'];
        }

        return $reading === null ? [] : ['unexpected_latin_'.$level.'_romanization'];
    }

    private function lyricsErrors(array $input, array $output): array
    {
        if (! is_bool($output['isMatch'] ?? null) || ! is_bool($output['isComplete'] ?? null) || ! is_array($output['cues'] ?? null)) {
            return ['invalid_lyrics_assessment'];
        }
        if (! $output['isMatch'] || (! $output['isComplete'] && ! $input['allowPartial'])) {
            return $output['cues'] === [] ? [] : ['unexpected_alignment'];
        }
        $partial = ! $output['isComplete'];
        $cueIds = array_column($input['cues'], 'cueId');
        if ($partial && array_column($output['cues'], 'cueId') !== $cueIds) {
            return ['partial_cue_identity_mismatch'];
        }
        $existingParts = array_column($input['existingParts'] ?? [], 'parts', 'cueId');
        $position = -1;
        $pastedCursor = 0;
        $usedExisting = false;
        foreach ($output['cues'] as $cue) {
            $next = array_search($cue['cueId'] ?? null, $cueIds, true);
            if ($next === false || $next <= $position || empty($cue['segments'])) {
                return ['invalid_timing_slot_order'];
            }
            $position = $next;
            $existingCursor = 0;
            $previousSource = null;
            foreach ($cue['segments'] as $segment) {
                $source = $segment['source'] ?? null;
                $end = $segment['endPartIndex'] ?? null;
                $start = $input['allowPartial'] ? ($segment['startPartIndex'] ?? null) : $pastedCursor;
                $separator = $input['allowPartial'] ? ($segment['separator'] ?? null) : '';
                if (! is_int($start) || ! is_int($end) || $start < 0 || $end < $start || ! in_array($separator, ['', ' '], true)) {
                    return ['invalid_source_segment'];
                }
                if (($previousSource === null || $previousSource === $source || ! $partial) && $separator !== '') {
                    return ['invalid_segment_separator'];
                }
                if ($source === 'pasted') {
                    if ($start !== $pastedCursor || $end >= count($input['lyricsParts'])) {
                        return ['invalid_pasted_part_order'];
                    }
                    $pastedCursor = $end + 1;
                } elseif ($source === 'existing' && $partial) {
                    if ($start < $existingCursor || $end >= count($existingParts[$cue['cueId']] ?? [])) {
                        return ['invalid_existing_part_order'];
                    }
                    if ($start > $existingCursor && $previousSource !== 'pasted') {
                        return ['unpreserved_existing_parts'];
                    }
                    $existingCursor = $end + 1;
                    $usedExisting = true;
                } else {
                    return ['invalid_segment_source'];
                }
                $previousSource = $source;
            }
            if ($partial && $previousSource === 'existing' && $existingCursor !== count($existingParts[$cue['cueId']] ?? [])) {
                return ['unpreserved_existing_parts'];
            }
        }
        if ($partial && ! $usedExisting) {
            return ['missing_existing_source'];
        }

        return $pastedCursor === count($input['lyricsParts']) ? [] : ['incomplete_lyrics_coverage'];
    }

    private function segmentation(array $case, array $output): array
    {
        if (! in_array($case['agent'], ['tokenization', 'analysis'], true)) {
            return [];
        }
        $metric = app(TokenizationBoundaryMetric::class);
        $scores = [];
        foreach ($output['cues'] ?? [] as $cue) {
            foreach ($case['cues'] as $source) {
                if (($cue['cueId'] ?? null) === $source['cueId']) {
                    $score = $metric->evaluate($source['cueId'], $case['sourceLanguage'], $source['sourceText'], $case['goldTokens'][$source['cueId']], array_column($cue['tokens'] ?? [], 'text'));
                    $scores[] = ['cueId' => $source['cueId'], 'metrics' => get_object_vars($score)];
                }
            }
        }

        return $scores;
    }

    private function nonempty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function latencySummary(array $values): array
    {
        sort($values);
        $count = count($values);

        return ['samples' => $count, 'median' => $count === 0 ? null : ($values[intdiv($count - 1, 2)] + $values[intdiv($count, 2)]) / 2,
            'p95' => $count === 0 ? null : $values[(int) ceil($count * 0.95) - 1]];
    }
}
