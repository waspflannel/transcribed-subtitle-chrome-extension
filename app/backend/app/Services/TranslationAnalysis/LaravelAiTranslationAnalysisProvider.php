<?php

namespace App\Services\TranslationAnalysis;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\Languages\LanguageCatalog;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use JsonException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use LogicException;
use Throwable;

class LaravelAiTranslationAnalysisProvider
{
    public function __construct(
        private readonly LearningTokenOutputValidator $tokenValidator,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function tokenize(array $cues, string $sourceLanguage): CueEnrichmentResult
    {
        if ($cues === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        $this->ensureProviderConfigured(CueTokenizationAgent::class);

        $tokenizedCues = [];
        $dialect = 'unknown';
        $sourceCues = array_values($cues);
        $batchSize = max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));

        foreach (array_chunk($sourceCues, $batchSize) as $batch) {
            $result = $this->tokenizeBatch($batch, $sourceCues, $sourceLanguage);

            array_push($tokenizedCues, ...$result->cues);

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult($tokenizedCues, $dialect);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(
        array $cues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization = true,
    ): CueEnrichmentResult {
        if ($cues === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        $this->ensureProviderConfigured(CueEnrichmentAgent::class);

        $enrichedCuesById = [];
        $dialect = 'unknown';
        $sourceCues = array_values($cues);
        $batchSize = max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));

        foreach (array_chunk($sourceCues, $batchSize) as $batch) {
            $result = $this->validatedEnrichedCueResult(
                $this->structuredResponse($this->promptAgent(
                    CueEnrichmentAgent::class,
                    $this->fullCardPrompt($batch, $sourceLanguage, $targetLanguage, $includeRomanization),
                )),
                $batch,
                $includeRomanization,
            );

            foreach ($result->cues as $cue) {
                $enrichedCuesById[(string) $cue['cueId']] = $cue;
            }

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => $enrichedCuesById[(string) $cue['cueId']],
                $sourceCues,
            ),
            $dialect,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function romanize(array $cues, string $sourceLanguage): CueEnrichmentResult
    {
        if ($cues === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        $this->ensureProviderConfigured(CueRomanizationAgent::class);

        $romanizedCuesById = [];
        $dialect = 'unknown';
        $sourceCues = array_values($cues);
        $batchSize = max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));

        foreach (array_chunk($sourceCues, $batchSize) as $batch) {
            $result = $this->romanizedResult(
                $this->structuredResponse($this->promptAgent(
                    CueRomanizationAgent::class,
                    $this->romanizationPrompt($batch, $sourceLanguage),
                )),
                $batch,
            );

            foreach ($result->cues as $cue) {
                $romanizedCuesById[(string) $cue['cueId']] = $cue;
            }

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => $romanizedCuesById[(string) $cue['cueId']],
                $sourceCues,
            ),
            $dialect,
        );
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

        $this->ensureProviderConfigured(LearningTokenCardAgent::class);

        $response = $this->structuredResponse($this->promptAgent(
            LearningTokenCardAgent::class,
            $this->tokenCardPrompt($cue, $token, $sourceLanguage, $targetLanguage),
        ));
        $outputToken = $response['token'] ?? null;

        if (! is_array($outputToken)) {
            $this->failInvalidOutput('missing_token');
        }

        return $this->validatedLearningToken($outputToken, $token, $tokenText);
    }

    /**
     * @param  class-string  $agentClass
     */
    private function promptAgent(string $agentClass, string $prompt, ?string $model = null): mixed
    {
        $resolvedModel = $model ?? $this->model($agentClass);

        try {
            $agent = $agentClass::make();

            return $agent->prompt($prompt, model: $resolvedModel);
        } catch (RateLimitedException $exception) {
            throw SubtitleProcessingException::rateLimited(
                'Subtitle AI processing is temporarily rate limited.',
                [
                    'provider' => Lab::OpenAI->value,
                    'adapter' => 'laravel-ai-sdk',
                    'model' => $resolvedModel,
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
                'model' => $resolvedModel,
                'exception' => $exception::class,
            ];

            if ($exception instanceof RequestException) {
                $context['status'] = $exception->response->status();
            }

            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI processing failed.', $context, $exception);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @param  array<int, array<string, mixed>>  $allCues
     * @param  array<string, string>  $qualityFailures
     *
     * @throws JsonException
     */
    private function tokenizationPrompt(
        array $sourceCues,
        string $sourceLanguage,
        array $allCues = [],
        array $qualityFailures = [],
    ): string {
        return json_encode([
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'instructions' => [
                'Tokenize this transcript.',
                'Return one tokenized cue for each input cue in the same order.',
                'Do not change cueId or index.',
                'Return tokens only; do not include source text, romanization, translations, glosses, grammar metadata, or learner-card metadata.',
                'Tokens must preserve source characters in source order.',
                'Token indexes must be zero-based and sequential within each cue.',
                'For space-delimited text, keep natural learner words or short fixed phrases.',
                'For no-space scripts, choose learner-friendly words or short phrases according to the language, not individual characters and not arbitrary chunks.',
                'Treat spaces between individual characters in no-space scripts as transcription artifacts and omit those spaces from token text.',
                'Do not return punctuation-only tokens.',
                'Prefer boundaries a beginner can tap for a useful word card.',
                'Prefer one learner-clickable lexical unit per token.',
                'Keep particles, case markers, short connectors, and auxiliaries separate when they function independently.',
                'Do not attach a leading or trailing function word to a neighboring content word.',
                'Avoid broad phrase chunks unless the expression is truly fixed and useful as one card.',
                'Japanese example: split "か聞いてみた" as "か", "聞いて", "みた", never as "か聞いてみた".',
                'Japanese example: split "みたいと" as "みたい", "と", never as "いと".',
                'For English-like spacing, "go to the store today" should not be one token; words or short fixed expressions are acceptable.',
                'Use unknown for dialect when it cannot be detected.',
                ...($qualityFailures === [] ? [] : [
                    'This is a retry for cues rejected by validation. Fix the listed rejection reason and return only valid boundaries.',
                ]),
            ],
            'qualityFailures' => array_map(
                fn (string $reason, string $cueId): array => [
                    'cueId' => $cueId,
                    'reason' => $reason,
                ],
                $qualityFailures,
                array_keys($qualityFailures),
            ),
            'cues' => array_map(
                fn (array $cue): array => $this->tokenizationCueInput($cue, $allCues === [] ? $sourceCues : $allCues),
                $sourceCues,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
     *
     * @throws JsonException
     */
    private function fullCardPrompt(
        array $sourceCues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization,
    ): string {
        return json_encode([
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
            'includeRomanization' => $includeRomanization,
            'instructions' => [
                'Return one enriched cue for each input cue in the same order.',
                'Do not change cueId, index, sourceText, token count, token indexes, or token text.',
                'Translate each cue into targetLanguageName.',
                'If sourceLanguage and targetLanguage are the same language, set translatedText to sourceText.',
                'Return exactly one token for each input token in the same order.',
                'Add short gloss or translation metadata for the target language.',
                'Add concise usage notes only when useful.',
                'Leave lemma, root, and partOfSpeech null unless useful.',
                'If includeRomanization is true, preserve provided romanization and add learner-standard romanization when useful, such as Hepburn for Japanese and pinyin for Mandarin.',
                'If includeRomanization is false, set cue and token romanization to null.',
                'Use unknown for dialect when it cannot be detected.',
            ],
            'cues' => array_map(
                fn (array $cue): array => [
                    ...Arr::only($cue, ['cueId', 'index', 'sourceText', 'romanization']),
                    'tokens' => array_map(
                        fn (array $token): array => Arr::only($token, ['index', 'text', 'normalizedText', 'romanization']),
                        array_values($cue['tokens']),
                    ),
                ],
                $sourceCues,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     *
     * @throws JsonException
     */
    private function romanizationPrompt(array $sourceCues, string $sourceLanguage): string
    {
        return json_encode([
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $sourceLanguage,
            'targetLanguageName' => LanguageCatalog::label($sourceLanguage),
            'instructions' => [
                'Romanize subtitle cues written in non-Latin scripts for transcript-first display.',
                'Do not translate, retokenize, or create learner cards.',
                'Return one cue for each input cue in the same order.',
                'Do not change cueId, index, sourceText, token count, token indexes, or token text.',
                'Set translatedText exactly equal to sourceText.',
                'Fill cue romanization and every token romanization with readable Latin-script pronunciation.',
                'Use learner-standard romanization, such as Hepburn for Japanese and pinyin for Mandarin.',
                'Use unknown for dialect when it cannot be detected.',
            ],
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
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     *
     * @throws JsonException
     */
    private function tokenCardPrompt(array $cue, array $token, string $sourceLanguage, string $targetLanguage): string
    {
        return json_encode([
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
            'instructions' => [
                'Return exactly one token object for requestedToken.',
                'The returned token text must match requestedToken.text.',
                'Include short gloss or translation metadata for the target language.',
                'Add lemma, root, partOfSpeech, romanization, or usageNote only when useful.',
                'For non-Latin source text, include romanization when helpful.',
                'For Latin-script languages, omit romanization unless it helps pronunciation.',
                'Use learner-standard romanization when applicable, such as Hepburn for Japanese and pinyin for Mandarin.',
            ],
            'cue' => Arr::only($cue, ['cueId', 'index', 'sourceText', 'romanization']),
            'requestedToken' => Arr::only($token, ['index', 'text', 'normalizedText', 'romanization']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @param  array<int, array<string, mixed>>  $allCues
     */
    private function tokenizeBatch(array $sourceCues, array $allCues, string $sourceLanguage): CueEnrichmentResult
    {
        $firstPass = $this->attemptTokenizedBatch($sourceCues, $allCues, $sourceLanguage);
        $tokenizedByCueId = [];
        $dialect = $firstPass['dialect'];

        foreach ($firstPass['cues'] as $cue) {
            $tokenizedByCueId[(string) $cue['cueId']] = $cue;
        }

        if ($firstPass['failures'] !== []) {
            $failedCues = array_map(
                fn (array $failure): array => $failure['cue'],
                $firstPass['failures'],
            );
            $retryPass = $this->attemptTokenizedBatch(
                $failedCues,
                $allCues,
                $sourceLanguage,
                $this->failureReasonsByCueId($firstPass['failures']),
                $this->tokenizationRetryModel(),
            );

            if ($dialect === 'unknown' && $retryPass['dialect'] !== 'unknown') {
                $dialect = $retryPass['dialect'];
            }

            foreach ($retryPass['cues'] as $cue) {
                $tokenizedByCueId[(string) $cue['cueId']] = $cue;
            }

            if ($retryPass['failures'] !== []) {
                $this->failTokenizationFailures($retryPass['failures']);
            }
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => $tokenizedByCueId[(string) $cue['cueId']],
                $sourceCues,
            ),
            $dialect,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @param  array<int, array<string, mixed>>  $allCues
     * @param  array<string, string>  $qualityFailures
     * @return array{cues: array<int, array<string, mixed>>, failures: array<int, array{cue: array<string, mixed>, reason: string}>, dialect: string}
     */
    private function attemptTokenizedBatch(
        array $sourceCues,
        array $allCues,
        string $sourceLanguage,
        array $qualityFailures = [],
        ?string $model = null,
    ): array {
        $output = $this->structuredResponse($this->promptAgent(
            CueTokenizationAgent::class,
            $this->tokenizationPrompt($sourceCues, $sourceLanguage, $allCues, $qualityFailures),
            $model,
        ));

        try {
            return $this->tokenizedBatchResult($output, $sourceCues);
        } catch (SubtitleProcessingException $exception) {
            return [
                'cues' => [],
                'failures' => array_map(
                    fn (array $cue): array => $this->tokenizationFailure($cue, $exception),
                    $sourceCues,
                ),
                'dialect' => $this->cleanString($output['dialect'] ?? null) ?? 'unknown',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     * @return array{cues: array<int, array<string, mixed>>, failures: array<int, array{cue: array<string, mixed>, reason: string}>, dialect: string}
     */
    private function tokenizedBatchResult(array $output, array $sourceCues): array
    {
        $dialect = $this->cleanString($output['dialect'] ?? null) ?? 'unknown';
        $outputCues = $this->validatedOutputCues($output, $sourceCues);
        $tokenizedCues = [];
        $failures = [];

        foreach ($sourceCues as $position => $sourceCue) {
            try {
                $tokenizedCues[] = $this->validatedTokenizedCue($sourceCue, $outputCues[$position], $position);
            } catch (SubtitleProcessingException $exception) {
                $failures[] = $this->tokenizationFailure($sourceCue, $exception);
            }
        }

        return [
            'cues' => $tokenizedCues,
            'failures' => $failures,
            'dialect' => $dialect,
        ];
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
     * @param  array<int, array{cue: array<string, mixed>, reason: string}>  $failures
     * @return array<string, string>
     */
    private function failureReasonsByCueId(array $failures): array
    {
        $reasons = [];

        foreach ($failures as $failure) {
            $reasons[(string) $failure['cue']['cueId']] = $failure['reason'];
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $cue
     * @return array{cue: array<string, mixed>, reason: string}
     */
    private function tokenizationFailure(array $cue, SubtitleProcessingException $exception): array
    {
        return [
            'cue' => $cue,
            'reason' => $this->cleanString($exception->context['reason'] ?? null) ?? 'invalid_tokenization',
        ];
    }

    /**
     * @param  array<int, array{cue: array<string, mixed>, reason: string}>  $failures
     */
    private function failTokenizationFailures(array $failures): never
    {
        $this->failInvalidOutput('tokenization_retry_failed', [
            'failures' => array_map(
                fn (array $failure): array => [
                    'cue_id' => $failure['cue']['cueId'] ?? null,
                    'reason' => $failure['reason'],
                ],
                $failures,
            ),
        ]);
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

            $translatedText = $this->cleanString($outputCue['translatedText'] ?? null);

            if ($translatedText === null) {
                $this->failInvalidOutput('missing_translation', ['cue_index' => $sourceCue['index']]);
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
                    false,
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
    private function romanizedResult(array $output, array $sourceCues): CueEnrichmentResult
    {
        $dialect = $this->cleanString($output['dialect'] ?? null) ?? 'unknown';
        $outputCues = $this->validatedOutputCues($output, $sourceCues);
        $cues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $outputCue = $outputCues[$position];
            $this->validateCueIdentity($sourceCue, $outputCue, $position);

            $cueRomanization = $this->cleanString($outputCue['romanization'] ?? null);

            if ($cueRomanization === null) {
                $this->failInvalidOutput('missing_romanization', [
                    'cue_index' => $sourceCue['index'],
                ]);
            }

            $cues[] = [
                ...$sourceCue,
                'translatedText' => $this->cleanString($sourceCue['translatedText'] ?? null)
                    ?? (string) $sourceCue['sourceText'],
                'romanization' => $cueRomanization,
                'tokens' => $this->tokensPreservingSource(
                    $outputCue['tokens'] ?? null,
                    $this->sourceTokens($sourceCue),
                    (int) $sourceCue['index'],
                    true,
                    true,
                ),
            ];
        }

        return new CueEnrichmentResult($cues, $dialect);
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
        bool $requireRomanization,
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

            if ($requireRomanization && $romanization === null) {
                $this->failInvalidOutput('missing_token_romanization', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

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
     * @return array<string, mixed>
     */
    private function structuredResponse(mixed $response): array
    {
        if (is_array($response)) {
            return $response;
        }

        if ($response instanceof Arrayable) {
            return $response->toArray();
        }

        $structured = $response->structured ?? null;

        if (is_array($structured)) {
            return $structured;
        }

        $this->failInvalidOutput('missing_structured_response');
    }

    /**
     * @param  class-string  $agentClass
     */
    private function ensureProviderConfigured(string $agentClass): void
    {
        $model = $this->model($agentClass);
        $apiKey = config('ai.providers.'.Lab::OpenAI->value.'.key');
        $url = config('ai.providers.'.Lab::OpenAI->value.'.url');

        if (! is_string($url) || trim($url) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI provider URL is not configured.', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model' => $model,
            ]);
        }

        if (! $agentClass::isFaked() && (! is_string($apiKey) || trim($apiKey) === '')) {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI provider is not configured.', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model' => $model,
            ]);
        }
    }

    /**
     * @param  class-string  $agentClass
     */
    private function model(string $agentClass): string
    {
        return match ($agentClass) {
            CueTokenizationAgent::class => $this->configuredOpenAiModel('tokenization.default'),
            CueRomanizationAgent::class => $this->configuredOpenAiModel('romanization.default'),
            CueEnrichmentAgent::class, LearningTokenCardAgent::class => $this->configuredOpenAiModel('enrichment.default'),
            default => throw new LogicException("Unsupported AI agent [{$agentClass}]."),
        };
    }

    private function tokenizationRetryModel(): string
    {
        return $this->configuredOpenAiModel('tokenization.retry');
    }

    private function configuredOpenAiModel(string $modelKey): string
    {
        $model = config('ai.providers.'.Lab::OpenAI->value.'.models.'.$modelKey);

        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model_key' => $modelKey,
            ]);
        }

        return trim($model);
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
