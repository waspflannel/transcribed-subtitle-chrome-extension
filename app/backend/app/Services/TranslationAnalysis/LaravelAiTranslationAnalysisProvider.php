<?php

namespace App\Services\TranslationAnalysis;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\EditedCueAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Codex\CodexService;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\ProviderAdmission;
use App\Services\Subtitles\ProviderExceptionPolicy;
use App\Services\Text\SubtitleText;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

class LaravelAiTranslationAnalysisProvider
{
    public function __construct(private readonly LearningTokenOutputValidator $tokenValidator) {}

    public function analyzeCueBatch(
        array $batch,
        array $allCues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeTranslation = true,
        bool $includeRomanization = false,
        ?Closure $beforeRetry = null,
        ?SubtitleModel $selection = null,
        bool $validateOutput = true,
        ?SubtitleJob $job = null,
    ): CueEnrichmentResult {
        if ($batch === [] || $allCues === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        $selection ??= SubtitleModel::configured();
        $input = $this->analysisInput($batch, $allCues, $sourceLanguage, $targetLanguage, $includeTranslation, $includeRomanization);
        $output = $this->promptAgent(CueAnalysisAgent::class, $input, $selection, $validateOutput, $job);
        if (! $validateOutput) {
            return $this->uncheckedAnalysis($output, $batch, $includeTranslation, $includeRomanization);
        }
        try {
            return $this->validatedAnalysis($output, $batch, $includeTranslation, $includeRomanization);
        } catch (SubtitleProcessingException $exception) {
            // One repeat for malformed output, only while the caller's run is active.
            if ($beforeRetry === null || ! $beforeRetry($exception)) {
                throw $exception;
            }
            Log::info('backend.analysis_batch_retried', [
                'provider' => $selection->provider,
                'model' => $selection->model,
                'cue_count' => count($batch),
                'reason' => $exception->context['reason'] ?? 'invalid_output',
            ]);
        }

        return $this->validatedAnalysis($this->promptAgent(CueAnalysisAgent::class, $input, $selection, job: $job), $batch, $includeTranslation, $includeRomanization);
    }

    private function uncheckedAnalysis(array $output, array $sourceCues, bool $includeTranslation, bool $includeRomanization): CueEnrichmentResult
    {
        $knownIds = array_flip(array_column($sourceCues, 'cueId'));
        $knownIndexes = array_flip(array_column($sourceCues, 'index'));
        $byId = [];
        $byIndex = [];
        $remaining = [];
        foreach (is_array($output['cues'] ?? null) ? $output['cues'] : [] as $cue) {
            $cue = is_array($cue) ? $cue : [];
            $id = $cue['cueId'] ?? null;
            $index = $cue['index'] ?? null;
            if (is_string($id) && isset($knownIds[$id]) && ! isset($byId[$id])) {
                $byId[$id] = $cue;
            } elseif (is_int($index) && isset($knownIndexes[$index]) && ! isset($byIndex[$index])) {
                $byIndex[$index] = $cue;
            } else {
                $remaining[] = $cue;
            }
        }

        $cues = [];
        foreach ($sourceCues as $source) {
            // Match by ID, then index; trust response order only for unidentifiable cues.
            $cue = $byId[$source['cueId']] ?? $byIndex[$source['index']] ?? array_shift($remaining) ?? [];
            $source['translatedText'] = $includeTranslation
                ? ($this->cleanString($cue['translatedText'] ?? null) ?? $source['sourceText']) : $source['sourceText'];
            unset($source['romanization']);
            if ($includeRomanization && ($reading = $this->cleanString($cue['romanization'] ?? null)) !== null) {
                $source['romanization'] = $reading;
            }
            $source['tokens'] = [];
            foreach (is_array($cue['tokens'] ?? null) ? $cue['tokens'] : [] as $token) {
                $text = is_array($token) ? $this->cleanString($token['text'] ?? null) : null;
                if ($text === null || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
                    continue;
                }
                $parsed = ['index' => count($source['tokens']), 'text' => $text, 'normalizedText' => $this->tokenValidator->normalizeTokenText($text)];
                if ($includeRomanization && ($reading = $this->cleanString($token['romanization'] ?? null)) !== null) {
                    $parsed['romanization'] = $reading;
                }
                $source['tokens'][] = $parsed;
            }
            if ($source['tokens'] === []) {
                // Published cues need at least one token; the whole line stays clickable.
                $source['tokens'][] = [
                    'index' => 0,
                    'text' => $source['sourceText'],
                    'normalizedText' => $this->tokenValidator->normalizeTokenText($source['sourceText']),
                    ...(isset($source['romanization']) ? ['romanization' => $source['romanization']] : []),
                ];
            }
            $cues[] = $source;
        }

        return new CueEnrichmentResult($cues);
    }

    public function validatedAnalysis(array $output, array $sourceCues, bool $includeTranslation, bool $includeRomanization): CueEnrichmentResult
    {
        $generated = $output['cues'] ?? null;
        if (! is_array($generated) || ! array_is_list($generated) || count($generated) !== count($sourceCues)) {
            $this->failInvalidOutput('cue_count_mismatch');
        }
        $cues = [];
        foreach (array_values($sourceCues) as $position => $source) {
            $cue = $generated[$position];
            if (! is_array($cue) || ($cue['cueId'] ?? null) !== $source['cueId'] || ($cue['index'] ?? null) !== $source['index']) {
                $this->failInvalidOutput('cue_identity_mismatch', ['cue_index' => $source['index']]);
            }
            $tokens = $this->tokenValidator->validatedGeneratedTokens($cue['tokens'] ?? null, $source['index']);
            $source['translatedText'] = $includeTranslation
                ? $this->requiredString($cue['translatedText'] ?? null, 'missing_translation') : $source['sourceText'];
            if ($includeRomanization) {
                $source['romanization'] = $this->requiredString($cue['romanization'] ?? null, 'missing_romanization');
                $tokenPosition = 0;
                foreach ($cue['tokens'] as $token) {
                    if (preg_match('/[\p{L}\p{N}]/u', $token['text']) !== 1) {
                        continue;
                    }
                    $tokens[$tokenPosition++]['romanization'] = $this->requiredString($token['romanization'] ?? null, 'missing_token_romanization');
                }
            } else {
                unset($source['romanization']);
            }
            $source['tokens'] = $tokens;
            $cues[] = $source;
        }

        return new CueEnrichmentResult($cues);
    }

    private function analysisInput(array $batch, array $allCues, string $sourceLanguage, string $targetLanguage, bool $includeTranslation, bool $includeRomanization): array
    {
        $allCues = array_values($allCues);
        $positions = array_flip(array_column($allCues, 'cueId'));
        $batchIds = array_flip(array_column($batch, 'cueId'));
        $context = [];
        foreach ($batch as $cue) {
            $position = $positions[$cue['cueId']] ?? null;
            if ($position === null) {
                $this->failInvalidOutput('cue_not_in_context');
            }
            foreach ([-2, -1, 1, 2] as $offset) {
                $neighbor = $allCues[$position + $offset] ?? null;
                if ($neighbor !== null && ! isset($batchIds[$neighbor['cueId']])) {
                    $context[$position + $offset] = Arr::only($neighbor, ['index', 'sourceText']);
                }
            }
        }
        ksort($context);

        return [
            ...$this->languages($sourceLanguage, $targetLanguage),
            'includeTranslation' => $includeTranslation,
            'includeRomanization' => $includeRomanization,
            'cues' => array_map(fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'sourceText']), array_values($batch)),
            'contextCues' => array_values($context),
        ];
    }

    public function enrichToken(array $cue, array $token, string $sourceLanguage, string $targetLanguage, ?SubtitleModel $selection = null, ?SubtitleJob $job = null): array
    {
        $output = $this->promptAgent(LearningTokenCardAgent::class, [
            ...$this->languages($sourceLanguage, $targetLanguage),
            'cue' => Arr::only($cue, ['sourceText', 'translatedText']),
            'requestedToken' => Arr::only($token, ['index', 'text']),
        ], $selection, job: $job);

        return $this->validatedCardToken($output['token'] ?? null, $token);
    }

    public function refreshEditedCue(array $cue, string $sourceLanguage, string $targetLanguage, bool $includeTranslation, bool $includeRomanization, ?SubtitleModel $selection = null, ?SubtitleJob $job = null): array
    {
        $output = $this->promptAgent(EditedCueAgent::class, [
            ...$this->cardInput([$cue], $sourceLanguage, $targetLanguage),
            'includeTranslation' => $includeTranslation,
            'includeRomanization' => $includeRomanization,
        ], $selection, job: $job);

        return $this->validatedEditedCue($output, $cue, $sourceLanguage, $targetLanguage, $includeTranslation, $includeRomanization);
    }

    public function validatedEditedCue(array $output, array $cue, string $sourceLanguage, string $targetLanguage, bool $includeTranslation, bool $includeRomanization): array
    {
        $result = $this->validatedCards($output, [$cue])->cues[0];
        unset($result['romanization']);
        foreach ($result['tokens'] as &$token) {
            unset($token['romanization']);
        }
        unset($token);
        $result['translatedText'] = $includeTranslation && $sourceLanguage !== $targetLanguage
            ? $this->requiredString($output['translatedText'] ?? null, 'missing_edited_cue_translation') : $cue['sourceText'];
        if ($includeRomanization) {
            $generated = $output['cues'][0];
            foreach ([$result, ...$result['tokens']] as $position => $item) {
                $text = $item['sourceText'] ?? $item['text'];
                $reading = $position === 0 ? ($generated['romanization'] ?? null) : ($generated['tokens'][$position - 1]['romanization'] ?? null);
                if ($reading !== null || preg_match('/(?!\p{Latin})\p{L}/u', $text) === 1) {
                    $reading = $this->requiredString($reading, 'missing_edited_cue_romanization');
                    if ($position === 0) {
                        $result['romanization'] = $reading;
                    } else {
                        $result['tokens'][$position - 1]['romanization'] = $reading;
                    }
                }
            }
        }

        return $result;
    }

    public function validatedCards(array $output, array $sourceCues): CueEnrichmentResult
    {
        $generated = $output['cues'] ?? null;
        if (! is_array($generated) || ! array_is_list($generated) || count($generated) !== count($sourceCues)) {
            $this->failInvalidOutput('cue_count_mismatch');
        }
        $cues = [];
        foreach (array_values($sourceCues) as $position => $source) {
            $cue = $generated[$position];
            if (! is_array($cue) || ($cue['cueId'] ?? null) !== $source['cueId'] || ($cue['index'] ?? null) !== $source['index']) {
                $this->failInvalidOutput('cue_identity_mismatch');
            }
            $tokens = $cue['tokens'] ?? null;
            if (! is_array($tokens) || ! array_is_list($tokens) || empty($source['tokens']) || count($tokens) !== count($source['tokens'])) {
                $this->failInvalidOutput('token_count_mismatch');
            }
            $source['tokens'] = array_map(fn (array $token, int $index): array => $this->validatedCardToken($tokens[$index], $token), array_values($source['tokens']), array_keys($tokens));
            $cues[] = $source;
        }

        return new CueEnrichmentResult($cues);
    }

    public function validatedCardToken(mixed $output, array $source): array
    {
        if (! is_array($output) || ($output['index'] ?? null) !== $source['index']) {
            $this->failInvalidOutput('token_identity_mismatch');
        }
        $token = Arr::only($source, ['index', 'text', 'normalizedText', 'romanization']);
        foreach (['translation', 'gloss', 'lemma', 'root', 'partOfSpeech', 'usageNote'] as $field) {
            if (($output[$field] ?? null) !== null) {
                $token[$field] = $this->requiredString($output[$field], 'invalid_card_field');
            }
        }
        if (! isset($token['translation']) && ! isset($token['gloss'])) {
            $this->failInvalidOutput('missing_token_meaning');
        }

        return $token;
    }

    private function cardInput(array $cues, string $sourceLanguage, string $targetLanguage): array
    {
        return [
            ...$this->languages($sourceLanguage, $targetLanguage),
            'cues' => array_map(fn (array $cue): array => [
                ...Arr::only($cue, ['cueId', 'index', 'sourceText', 'translatedText']),
                'tokens' => array_map(fn (array $token): array => Arr::only($token, ['index', 'text']), $cue['tokens']),
            ], $cues),
        ];
    }

    private function languages(string $sourceLanguage, string $targetLanguage): array
    {
        return [
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
        ];
    }

    private function requiredString(mixed $value, string $reason): string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->failInvalidOutput($reason);
        }

        return trim($value);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = SubtitleText::collapseWhitespace($value);

        return $cleaned === '' ? null : $cleaned;
    }

    private function failInvalidOutput(string $reason, array $context = []): never
    {
        throw SubtitleProcessingException::enrichmentFailed(
            'Subtitle enrichment produced invalid output.',
            [
                'reason' => $reason,
                ...$context,
            ],
        );
    }

    private function promptAgent(string $agentClass, array $input, ?SubtitleModel $selection, bool $validateOutput = true, ?SubtitleJob $job = null): array
    {
        $selection ??= SubtitleModel::configured();
        try {
            $agent = $agentClass === CueAnalysisAgent::class
                ? CueAnalysisAgent::make(
                    sourceLanguage: $input['sourceLanguage'],
                    includeTranslation: $input['includeTranslation'] ?? true,
                    includeRomanization: $input['includeRomanization'] ?? false,
                )
                : $agentClass::make(sourceLanguage: $input['sourceLanguage']);
            if ($selection->provider === 'codex') {
                return app(ProviderAdmission::class)->run($selection->provider, $job,
                    fn (): array => app(CodexService::class)->prompt($agent, $input, $selection));
            }
            $response = app(ProviderAdmission::class)->run($selection->provider, $job, fn () => $agent->prompt(
                json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                provider: $selection->provider,
                model: $selection->model,
            ));

            if ($validateOutput && $response->steps->last()?->finishReason === FinishReason::Length) {
                throw SubtitleProcessingException::enrichmentFailed('Subtitle AI output exceeded its token limit.', [
                    'provider' => $selection->provider,
                    'agent' => $agentClass,
                    'reason' => 'output_token_limit',
                ]);
            }

            return $response->toArray();
        } catch (Throwable $exception) {
            throw ProviderExceptionPolicy::classify($exception, [
                'provider' => $selection->provider,
                'adapter' => $selection->provider === 'codex' ? 'codex-app-server' : 'laravel-ai-sdk',
                'agent' => $agentClass,
            ]);
        }
    }
}
