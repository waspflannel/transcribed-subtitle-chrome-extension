<?php

namespace App\Console\Commands;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\TokenizationBoundaryMetric;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

#[Signature('subtitles:eval-agents {--agent=all : analysis, enrichment, card, edited, lyrics, or all} {--case= : Run one held-out case ID} {--repeat=1 : Repetitions per case} {--model= : Override the globally selected provider model for this run} {--out= : Write the JSON review report to this path}')]
#[Description('Evaluate held-out agent cases with first-response evidence, pipeline outcomes, and pending human semantic review. Makes live calls unless agents are faked.')]
class EvalAgents extends Command
{
    private const AGENTS = ['analysis', 'enrichment', 'card', 'edited', 'lyrics'];

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
        $modelKey = 'ai.providers.'.SubtitleModel::provider().'.models.text.default';
        $originalModel = config($modelKey);
        if (trim((string) $this->option('model')) !== '') {
            config([$modelKey => trim((string) $this->option('model'))]);
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
                'provider' => SubtitleModel::provider(), 'model' => SubtitleModel::model(), 'createdAt' => now()->toIso8601String(),
                'scope' => 'Four agents use production provider methods with one response per operation. Lyrics is agent-only; no lyrics pipeline validation is claimed.',
                'validityScope' => 'Contract checks cover identity, usable tokens, requested translations/readings, and card meanings. Lyrics has no automated contract checks; its check result is null and output is for human review only. These checks are not a general JSON Schema validator or a language-quality judge.',
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
            config([$modelKey => $originalModel]);
        }

        return collect($results)->every(fn (array $case): bool => collect($case['runs'])->every(
            fn (array $run): bool => $run['pipelineCompleted'] && ($case['agent'] === 'lyrics' || $run['firstResponseContractChecksPassed'] === true),
        )) ? self::SUCCESS : self::FAILURE;
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
                'provider' => $event->prompt->agent->provider(), 'model' => $event->prompt->model, 'instructionsHash' => hash('sha256', (string) $event->prompt->agent->instructions()),
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
                $attempt['segmentation'] = $attempt['contractErrors'] === [] ? $this->segmentation($this->activeCase, $attempt['output']) : [];
            } catch (Throwable) {
                $attempt['contractErrors'] = ['malformed_output'];
            }
            if ($this->activeCase['agent'] !== 'lyrics' && $event->response->steps->last()?->finishReason === FinishReason::Length) {
                $attempt['contractErrors'][] = 'output_token_limit';
            }
            $attempt['contractChecksPassed'] = $this->activeCase['agent'] === 'lyrics' ? null : $attempt['contractErrors'] === [];
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
                'analysis' => $provider->analyzeCueBatch($cues, $context, $source, $target, $case['includeTranslation'], $case['includeRomanization']),
                'card' => $provider->enrichToken($cues[0], $cues[0]['tokens'][$case['requestedTokenPosition']], $source, $target),
                'edited' => $provider->refreshEditedCue($cues[0], $source, $target, true, true),
                'lyrics' => LyricsAlignmentAgent::make()
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
            'attempts' => $attempts,
        ];
    }

    private function contractErrors(string $agent, array $input, array $output): array
    {
        if ($agent === 'lyrics') {
            return [];
        }
        $provider = app(LaravelAiTranslationAnalysisProvider::class);
        try {
            match ($agent) {
                'analysis' => $provider->validatedAnalysis($output, $this->activeCase['cues'], $input['includeTranslation'], $input['includeRomanization']),
                'enrichment' => $provider->validatedCards($output, $this->activeCase['cues']),
                'card' => $provider->validatedCardToken($output['token'] ?? null, $input['requestedToken']),
                'edited' => $provider->validatedEditedCue($output, $this->activeCase['cues'][0], $input['sourceLanguage'], $input['targetLanguage'], $input['includeTranslation'], $input['includeRomanization']),
            };
        } catch (SubtitleProcessingException $exception) {
            return [$exception->context['reason'] ?? $exception->publicCode];
        }

        return [];
    }

    private function segmentation(array $case, array $output): array
    {
        if ($case['agent'] !== 'analysis' || empty($case['goldTokens'])) {
            return [];
        }
        $output = ['cues' => app(LaravelAiTranslationAnalysisProvider::class)->validatedAnalysis($output, $case['cues'], $case['includeTranslation'], $case['includeRomanization'])->cues];
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
