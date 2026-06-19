<?php

namespace App\Services\TranslationAnalysis;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\CueTranslationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\Languages\LanguageCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class LaravelAiTranslationAnalysisProvider
{
    public function __construct(
        private readonly LearningTokenOutputValidator $tokenValidator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function tokenizeCueBatch(array $batch, array $allCues, string $sourceLanguage): CueEnrichmentResult
    {
        if ($batch === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        if ($allCues === []) {
            $this->failInvalidOutput('empty_context_cues');
        }

        return $this->tokenizeBatch($batch, $sourceLanguage, $allCues);
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    private function tokenizeBatch(array $batch, string $sourceLanguage, array $allCues): CueEnrichmentResult
    {
        $output = $this->promptAgent(
            CueTokenizationAgent::class,
            $this->tokenizationInput($batch, $sourceLanguage, $allCues),
        );

        try {
            return $this->tokenizedBatchResult($output, $batch);
        } catch (SubtitleProcessingException $exception) {
            if (! $this->shouldRetryTokenizationBatch($exception, count($batch))) {
                throw $exception;
            }

            $reason = $exception->context['reason'] ?? 'unknown';

            Log::info('backend.tokenization_batch_retried', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model' => $this->openAiModel('tokenization'),
                'source_language' => $sourceLanguage,
                'cue_count' => count($batch),
                'reason' => is_string($reason) ? $reason : 'unknown',
            ]);

            $splitAt = intdiv(count($batch), 2);
            $left = $this->tokenizeBatch(array_slice($batch, 0, $splitAt), $sourceLanguage, $allCues);
            $right = $this->tokenizeBatch(array_slice($batch, $splitAt), $sourceLanguage, $allCues);

            return new CueEnrichmentResult(
                [...$left->cues, ...$right->cues],
                $left->sourceDialect !== 'unknown' ? $left->sourceDialect : $right->sourceDialect,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function enrichCueBatch(
        array $batch,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization = true,
    ): CueEnrichmentResult {
        if ($batch === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        return $this->validatedEnrichedCueResult(
            $this->promptAgent(
                CueEnrichmentAgent::class,
                $this->cueEnrichmentInput($batch, $sourceLanguage, $targetLanguage, $includeRomanization),
            ),
            $batch,
            $includeRomanization,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function translateCueBatch(
        array $batch,
        string $sourceLanguage,
        string $targetLanguage,
        array $allCues,
    ): CueEnrichmentResult {
        if ($batch === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        if ($allCues === []) {
            $this->failInvalidOutput('empty_context_cues');
        }

        return $this->translatedResult(
            $this->promptAgent(
                CueTranslationAgent::class,
                $this->translationInput($batch, $sourceLanguage, $targetLanguage, $allCues),
            ),
            $batch,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function romanizeCueBatch(array $batch, string $sourceLanguage): CueEnrichmentResult
    {
        if ($batch === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        return $this->romanizeBatch($batch, $sourceLanguage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function romanizeBatch(array $batch, string $sourceLanguage): CueEnrichmentResult
    {
        $output = $this->promptAgent(
            CueRomanizationAgent::class,
            $this->romanizationInput($batch, $sourceLanguage),
        );

        try {
            return $this->romanizedResult($output, $batch);
        } catch (SubtitleProcessingException $exception) {
            if (! $this->shouldRetryRomanizationBatch($exception, count($batch))) {
                throw $exception;
            }

            $reason = $exception->context['reason'] ?? 'unknown';

            Log::info('backend.romanization_batch_retried', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model' => $this->openAiModel('romanization'),
                'source_language' => $sourceLanguage,
                'cue_count' => count($batch),
                'reason' => is_string($reason) ? $reason : 'unknown',
            ]);

            $splitAt = intdiv(count($batch), 2);
            $left = $this->romanizeBatch(array_slice($batch, 0, $splitAt), $sourceLanguage);
            $right = $this->romanizeBatch(array_slice($batch, $splitAt), $sourceLanguage);

            return new CueEnrichmentResult(
                [...$left->cues, ...$right->cues],
                $left->sourceDialect !== 'unknown' ? $left->sourceDialect : $right->sourceDialect,
            );
        }
    }

    private function shouldRetryRomanizationBatch(SubtitleProcessingException $exception, int $cueCount): bool
    {
        if ($cueCount <= 1 || $exception->publicCode !== 'enrichment_failed') {
            return false;
        }

        return in_array($exception->context['reason'] ?? null, [
            'missing_cues',
            'cue_count_mismatch',
            'invalid_cue',
            'cue_identity_mismatch',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function enrichToken(array $cue, array $token, string $sourceLanguage, string $targetLanguage): array
    {
        $sourceText = $this->cleanString($cue['sourceText'] ?? null);
        $tokenText = $this->cleanString($token['text'] ?? null);

        if ($sourceText === null || $tokenText === null || ! is_int($token['index'] ?? null)) {
            $this->failInvalidOutput('invalid_token_context');
        }

        $response = $this->promptAgent(
            LearningTokenCardAgent::class,
            $this->learningTokenCardInput($cue, $token, $sourceLanguage, $targetLanguage),
        );
        $outputToken = $response['token'] ?? null;

        if (! is_array($outputToken)) {
            $this->failInvalidOutput('missing_token');
        }

        return $this->validatedLearningToken($outputToken, $token, $tokenText);
    }

    /**
     * @param  class-string  $agentClass
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function promptAgent(string $agentClass, array $input): array
    {
        try {
            return $agentClass::make()
                ->prompt($this->encodeAgentInput($input))
                ->toArray();
        } catch (RateLimitedException $exception) {
            throw SubtitleProcessingException::rateLimited(
                'Subtitle AI processing is temporarily rate limited.',
                [
                    'provider' => Lab::OpenAI->value,
                    'adapter' => 'laravel-ai-sdk',
                    'agent' => $agentClass,
                    'exception' => $exception::class,
                ],
                $exception,
            );
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $context = [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'agent' => $agentClass,
                'exception' => $exception::class,
            ];

            if ($exception instanceof ConnectionException) {
                throw SubtitleProcessingException::providerUnavailable(
                    'Subtitle AI provider did not respond.',
                    [...$context, 'reason' => 'connection_failure'],
                    $exception,
                );
            }

            if ($exception instanceof RequestException) {
                $context['status'] = $exception->response->status();

                if ($exception->response->status() === 429) {
                    throw SubtitleProcessingException::rateLimited(
                        'Subtitle AI processing is temporarily rate limited.',
                        $context,
                        $exception,
                    );
                }

                if ($exception->response->serverError()) {
                    throw SubtitleProcessingException::providerUnavailable(
                        'Subtitle AI provider is temporarily unavailable.',
                        $context,
                        $exception,
                    );
                }
            }

            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI processing failed.', $context, $exception);
        }
    }

    private function shouldRetryTokenizationBatch(SubtitleProcessingException $exception, int $cueCount): bool
    {
        if ($cueCount <= 1 || $exception->publicCode !== 'enrichment_failed') {
            return false;
        }

        return in_array($exception->context['reason'] ?? null, [
            'missing_cues',
            'cue_count_mismatch',
            'invalid_cue',
            'cue_identity_mismatch',
            'invalid_tokens',
            'empty_tokens',
            'invalid_token',
            'invalid_token_index',
            'invalid_token_text',
            'token_text_not_in_source',
        ], true);
    }

    private function openAiModel(string $purpose): string
    {
        return (string) config('ai.providers.'.Lab::OpenAI->value.'.models.'.$purpose.'.default');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @param  array<int, array<string, mixed>>  $allCues
     * @return array<string, mixed>
     */
    private function tokenizationInput(
        array $sourceCues,
        string $sourceLanguage,
        array $allCues,
    ): array {
        return [
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'cues' => array_map(
                fn (array $cue): array => $this->tokenizationCueInput($cue, $allCues),
                $sourceCues,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<int, array<string, mixed>>  $allCues
     * @return array<string, mixed>
     */
    private function tokenizationCueInput(array $cue, array $allCues): array
    {
        $position = $this->cuePosition($cue, $allCues);
        $previousCue = $position > 0 ? $allCues[$position - 1] : null;
        $nextCue = $allCues[$position + 1] ?? null;

        return [
            ...Arr::only($cue, ['cueId', 'index', 'startMs', 'endMs', 'sourceText']),
            'previousCueText' => $previousCue === null ? null : (string) $previousCue['sourceText'],
            'nextCueText' => $nextCue === null ? null : (string) $nextCue['sourceText'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<int, array<string, mixed>>  $allCues
     */
    private function cuePosition(array $cue, array $allCues): int
    {
        foreach (array_values($allCues) as $position => $candidate) {
            if (($candidate['cueId'] ?? null) === ($cue['cueId'] ?? null)) {
                return $position;
            }
        }

        $this->failInvalidOutput('cue_not_in_context', [
            'cue_id' => $cue['cueId'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @return array<string, mixed>
     */
    private function cueEnrichmentInput(
        array $sourceCues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization,
    ): array {
        return [
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
            'includeRomanization' => $includeRomanization,
            'cues' => array_map(
                fn (array $cue): array => [
                    ...Arr::only($cue, ['cueId', 'index', 'sourceText', 'translatedText', 'romanization']),
                    'tokens' => array_map(
                        fn (array $token): array => Arr::only($token, ['index', 'text', 'normalizedText', 'romanization']),
                        array_values($cue['tokens']),
                    ),
                ],
                $sourceCues,
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @return array<string, mixed>
     */
    private function translationInput(
        array $sourceCues,
        string $sourceLanguage,
        string $targetLanguage,
        array $allCues,
    ): array {
        return [
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
            'cues' => array_map(
                fn (array $cue): array => $this->translationCueInput($cue, $allCues),
                $sourceCues,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<int, array<string, mixed>>  $allCues
     * @return array<string, mixed>
     */
    private function translationCueInput(array $cue, array $allCues): array
    {
        $position = $this->cuePosition($cue, $allCues);
        $previousCue = $position > 0 ? $allCues[$position - 1] : null;
        $nextCue = $allCues[$position + 1] ?? null;

        return [
            ...Arr::only($cue, ['cueId', 'index', 'sourceText']),
            'previousCueText' => $previousCue === null ? null : (string) $previousCue['sourceText'],
            'nextCueText' => $nextCue === null ? null : (string) $nextCue['sourceText'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @return array<string, mixed>
     */
    private function romanizationInput(array $sourceCues, string $sourceLanguage): array
    {
        return [
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $sourceLanguage,
            'targetLanguageName' => LanguageCatalog::label($sourceLanguage),
            'cues' => array_map(
                fn (array $cue): array => [
                    ...Arr::only($cue, ['cueId', 'index', 'sourceText']),
                    'tokens' => array_map(
                        fn (array $token): array => Arr::only($token, ['index', 'text']),
                        array_values($cue['tokens']),
                    ),
                ],
                $sourceCues,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    private function learningTokenCardInput(array $cue, array $token, string $sourceLanguage, string $targetLanguage): array
    {
        return [
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
            'cue' => Arr::only($cue, ['cueId', 'index', 'sourceText', 'romanization']),
            'requestedToken' => Arr::only($token, ['index', 'text', 'normalizedText', 'romanization']),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function tokenizedBatchResult(array $output, array $sourceCues): CueEnrichmentResult
    {
        $dialect = $this->cleanString($output['dialect'] ?? null) ?? 'unknown';
        $outputCues = $this->validatedOutputCues($output, $sourceCues);
        $tokenizedCues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $tokenizedCues[] = $this->validatedTokenizedCue($sourceCue, $outputCues[$position], $position);
        }

        return new CueEnrichmentResult($tokenizedCues, $dialect);
    }

    /**
     * @param  array<string, mixed>  $sourceCue
     * @param  array<string, mixed>  $outputCue
     * @return array<string, mixed>
     */
    private function validatedTokenizedCue(array $sourceCue, array $outputCue, int $position): array
    {
        $this->validateCueIdentity($sourceCue, $outputCue, $position, validateSourceText: false);

        return [
            ...$sourceCue,
            'translatedText' => (string) $sourceCue['sourceText'],
            'tokens' => $this->tokenValidator->validatedGeneratedTokens(
                $outputCue['tokens'] ?? null,
                (string) $sourceCue['sourceText'],
                (int) $sourceCue['index'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function validatedEnrichedCueResult(array $output, array $sourceCues, bool $includeRomanization): CueEnrichmentResult
    {
        $dialect = $this->cleanString($output['dialect'] ?? null) ?? 'unknown';
        $outputCues = $this->validatedOutputCues($output, $sourceCues);
        $enrichedCues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $outputCue = $outputCues[$position];
            $this->validateCueIdentity($sourceCue, $outputCue, $position);

            $sourceTranslatedText = $this->cleanString($sourceCue['translatedText'] ?? null);

            if ($sourceTranslatedText === null) {
                $this->failInvalidOutput('missing_source_translation', ['cue_index' => $sourceCue['index']]);
            }

            $translatedText = $this->cleanString($outputCue['translatedText'] ?? null);

            if ($translatedText === null) {
                $this->failInvalidOutput('missing_translation', ['cue_index' => $sourceCue['index']]);
            }

            if ($translatedText !== $sourceTranslatedText) {
                $this->failInvalidOutput('translation_identity_mismatch', ['cue_index' => $sourceCue['index']]);
            }

            $enrichedCue = [
                'cueId' => $sourceCue['cueId'],
                'index' => $sourceCue['index'],
                'startMs' => $sourceCue['startMs'],
                'endMs' => $sourceCue['endMs'],
                'sourceText' => $sourceCue['sourceText'],
                'translatedText' => $translatedText,
                'tokens' => $this->tokensPreservingSource(
                    $outputCue['tokens'] ?? null,
                    $this->sourceTokens($sourceCue),
                    (int) $sourceCue['index'],
                    $includeRomanization,
                ),
            ];

            $romanization = $this->cleanString($sourceCue['romanization'] ?? null)
                ?? $this->cleanString($outputCue['romanization'] ?? null);

            if ($includeRomanization && $romanization !== null) {
                $enrichedCue['romanization'] = $romanization;
            }

            $enrichedCues[] = $enrichedCue;
        }

        return new CueEnrichmentResult($enrichedCues, $dialect);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function translatedResult(array $output, array $sourceCues): CueEnrichmentResult
    {
        $outputCues = $this->validatedOutputCues($output, $sourceCues);
        $cues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $outputCue = $outputCues[$position];
            $this->validateCueIdentity($sourceCue, $outputCue, $position, validateSourceText: false);

            $translatedText = $this->cleanString($outputCue['translatedText'] ?? null)
                ?? (string) $sourceCue['sourceText'];

            $cues[] = [
                ...$sourceCue,
                'translatedText' => $translatedText,
            ];
        }

        return new CueEnrichmentResult($cues, 'unknown');
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function romanizedResult(array $output, array $sourceCues): CueEnrichmentResult
    {
        $dialect = $this->cleanString($output['dialect'] ?? null) ?? 'unknown';
        $outputCues = $this->validatedOutputCues($output, $sourceCues);
        $cues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $outputCue = $outputCues[$position];
            $this->validateCueIdentity($sourceCue, $outputCue, $position, validateSourceText: false);

            $sourceTokens = $this->sourceTokens($sourceCue);
            $romanizationByIndex = $this->romanizationByIndex($outputCue['tokens'] ?? null);

            $tokens = [];
            foreach ($sourceTokens as $sourceToken) {
                $token = [
                    'index' => $sourceToken['index'],
                    'text' => $sourceToken['text'],
                    'normalizedText' => $sourceToken['normalizedText'],
                ];

                $tokenRomanization = $romanizationByIndex[$sourceToken['index']] ?? null;

                if ($tokenRomanization !== null) {
                    $token['romanization'] = $tokenRomanization;
                }

                $tokens[] = $token;
            }

            $cue = [
                ...$sourceCue,
                'translatedText' => $this->cleanString($sourceCue['translatedText'] ?? null)
                    ?? (string) $sourceCue['sourceText'],
            ];

            $cueRomanization = $this->cleanString($outputCue['romanization'] ?? null);

            if ($cueRomanization !== null) {
                $cue['romanization'] = $cueRomanization;
            }

            $cue['tokens'] = $tokens;
            $cues[] = $cue;
        }

        return new CueEnrichmentResult($cues, $dialect);
    }

    /**
     * @return array<int, string>
     */
    private function romanizationByIndex(mixed $outputTokens): array
    {
        if (! is_array($outputTokens)) {
            return [];
        }

        $map = [];

        foreach ($outputTokens as $outputToken) {
            if (! is_array($outputToken)) {
                continue;
            }

            $index = $outputToken['index'] ?? null;

            if (! is_int($index)) {
                continue;
            }

            $romanization = $this->cleanString($outputToken['romanization'] ?? null);

            if ($romanization !== null) {
                $map[$index] = $romanization;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @return array<int, array<string, mixed>>
     */
    private function validatedOutputCues(array $output, array $sourceCues): array
    {
        $outputCues = $output['cues'] ?? null;

        if (! is_array($outputCues)) {
            $this->failInvalidOutput('missing_cues');
        }

        if (count($outputCues) !== count($sourceCues)) {
            $this->failInvalidOutput('cue_count_mismatch', [
                'expected_count' => count($sourceCues),
                'actual_count' => count($outputCues),
            ]);
        }

        foreach ($outputCues as $position => $outputCue) {
            if (! is_array($outputCue)) {
                $this->failInvalidOutput('invalid_cue', ['cue_position' => $position]);
            }
        }

        return array_values($outputCues);
    }

    /**
     * @param  array<string, mixed>  $sourceCue
     * @param  array<string, mixed>  $outputCue
     */
    private function validateCueIdentity(
        array $sourceCue,
        array $outputCue,
        int $position,
        bool $validateSourceText = true,
    ): void {
        foreach (['cueId', ...($validateSourceText ? ['sourceText'] : [])] as $field) {
            if (($outputCue[$field] ?? null) !== $sourceCue[$field]) {
                $this->failInvalidOutput('cue_identity_mismatch', [
                    'cue_position' => $position,
                    'field' => $field,
                ]);
            }
        }

        if (($outputCue['index'] ?? null) !== $sourceCue['index']) {
            $this->failInvalidOutput('cue_identity_mismatch', [
                'cue_position' => $position,
                'field' => 'index',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $sourceCue
     * @return array<int, array<string, mixed>>
     */
    private function sourceTokens(array $sourceCue): array
    {
        $tokens = $sourceCue['tokens'] ?? null;

        if (! is_array($tokens) || $tokens === []) {
            $this->failInvalidOutput('missing_source_tokens', [
                'cue_index' => $sourceCue['index'] ?? null,
            ]);
        }

        return array_values($tokens);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceTokens
     * @return array<int, array<string, mixed>>
     */
    private function tokensPreservingSource(
        mixed $outputTokens,
        array $sourceTokens,
        int $cueIndex,
        bool $includeRomanization,
    ): array {
        if (! is_array($outputTokens)) {
            $this->failInvalidOutput('invalid_tokens', ['cue_index' => $cueIndex]);
        }

        if (count($outputTokens) !== count($sourceTokens)) {
            $this->failInvalidOutput('token_count_mismatch', [
                'cue_index' => $cueIndex,
                'expected_count' => count($sourceTokens),
                'actual_count' => count($outputTokens),
            ]);
        }

        $tokens = [];

        foreach (array_values($sourceTokens) as $position => $sourceToken) {
            $outputToken = $outputTokens[$position] ?? null;

            if (! is_array($outputToken)) {
                $this->failInvalidOutput('invalid_token', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $sourceIndex = $sourceToken['index'];
            $sourceText = $this->cleanString($sourceToken['text']);

            if (($outputToken['index'] ?? null) !== $sourceIndex || ($outputToken['text'] ?? null) !== $sourceText) {
                $this->failInvalidOutput('token_identity_mismatch', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $token = [
                'index' => $sourceIndex,
                'text' => $sourceText,
                'normalizedText' => $sourceToken['normalizedText'],
            ];

            foreach (['lemma', 'root', 'partOfSpeech', 'translation', 'gloss', 'usageNote'] as $field) {
                $value = $this->cleanString($outputToken[$field] ?? null);

                if ($value !== null) {
                    $token[$field] = $value;
                }
            }

            $romanization = $this->cleanString($sourceToken['romanization'] ?? null)
                ?? $this->cleanString($outputToken['romanization'] ?? null);

            if ($includeRomanization && $romanization !== null) {
                $token['romanization'] = $romanization;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $outputToken
     * @param  array<string, mixed>  $sourceToken
     * @return array<string, mixed>
     */
    private function validatedLearningToken(array $outputToken, array $sourceToken, string $tokenText): array
    {
        if (($outputToken['text'] ?? null) !== $tokenText || ($outputToken['index'] ?? null) !== $sourceToken['index']) {
            $this->failInvalidOutput('token_identity_mismatch');
        }

        $token = [
            'index' => (int) $sourceToken['index'],
            'text' => $tokenText,
            'normalizedText' => $sourceToken['normalizedText'],
        ];

        foreach (['lemma', 'root', 'partOfSpeech', 'translation', 'gloss', 'romanization', 'usageNote'] as $field) {
            $value = $this->cleanString($outputToken[$field] ?? null);

            if ($value !== null) {
                $token[$field] = $value;
            }
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function encodeAgentInput(array $input): string
    {
        return json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $cleaned === '' ? null : $cleaned;
    }

    /**
     * @param  array<string, mixed>  $context
     */
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
}
