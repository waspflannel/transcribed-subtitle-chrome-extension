<?php

namespace App\Services\TranslationAnalysis;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\Languages\LanguageCatalog;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use JsonException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

class LaravelAiTranslationAnalysisProvider implements TranslationAnalysisProvider
{
    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(array $cues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        if ($cues === []) {
            $this->failInvalidOutput('empty_source_cues');
        }

        $this->ensureProviderConfigured(CueEnrichmentAgent::class);

        $enrichedCues = [];
        $dialect = 'unknown';
        $batchSize = max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));

        foreach (array_chunk(array_values($cues), $batchSize) as $batch) {
            $result = $this->validatedCueResult(
                $this->structuredResponse($this->promptAgent(
                    CueEnrichmentAgent::class,
                    $this->fullCardPrompt($batch, $sourceLanguage, $targetLanguage),
                )),
                $batch,
            );

            array_push($enrichedCues, ...$result->cues);

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult($enrichedCues, $dialect);
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

        $romanizedCues = [];
        $dialect = 'unknown';
        $batchSize = max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));

        foreach (array_chunk(array_values($cues), $batchSize) as $batch) {
            $result = $this->romanizedResult(
                $batch,
                $this->validatedCueResult(
                    $this->structuredResponse($this->promptAgent(
                        CueRomanizationAgent::class,
                        $this->romanizationPrompt($batch, $sourceLanguage),
                    )),
                    $batch,
                ),
            );

            array_push($romanizedCues, ...$result->cues);

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult($romanizedCues, $dialect);
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
    private function promptAgent(string $agentClass, string $prompt): mixed
    {
        try {
            return $agentClass::make()->prompt($prompt);
        } catch (RateLimitedException $exception) {
            throw SubtitleProcessingException::rateLimited(
                'Subtitle enrichment is temporarily rate limited.',
                [
                    'provider' => Lab::OpenAI->value,
                    'adapter' => 'laravel-ai-sdk',
                    'model' => $this->model(),
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
                'model' => $this->model(),
                'exception' => $exception::class,
            ];

            if ($exception instanceof RequestException) {
                $context['status'] = $exception->response->status();
            }

            throw SubtitleProcessingException::enrichmentFailed('Subtitle enrichment failed.', $context, $exception);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     *
     * @throws JsonException
     */
    private function fullCardPrompt(array $sourceCues, string $sourceLanguage, string $targetLanguage): string
    {
        return json_encode([
            'sourceLanguage' => $sourceLanguage,
            'sourceLanguageName' => LanguageCatalog::label($sourceLanguage),
            'targetLanguage' => $targetLanguage,
            'targetLanguageName' => LanguageCatalog::label($targetLanguage),
            'instructions' => [
                'Return one enriched cue for each input cue in the same order.',
                'Do not change cueId, index, or sourceText.',
                'Translate each cue into targetLanguageName.',
                'If sourceLanguage and targetLanguage are the same language, set translatedText to sourceText.',
                'Return one lightweight token for each visible source word or meaningful short phrase.',
                'Use token indexes as zero-based source order within each cue.',
                'Do not skip ordinary words; the UI uses tokens to render clickable cards.',
                'Include exact token text and short gloss or translation metadata for the target language.',
                'Add concise usage notes only when useful.',
                'For non-Latin source text, include cue and token romanization when helpful.',
                'For Latin-script source languages, omit romanization unless it helps pronunciation.',
                'Leave lemma, root, and partOfSpeech null unless useful.',
                'Use unknown for dialect when it cannot be detected.',
            ],
            'cues' => array_map(
                fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'sourceText']),
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
                'Do not translate or create learner cards.',
                'Return one cue for each input cue in the same order.',
                'Do not change cueId, index, sourceText, token indexes, or token text.',
                'Set translatedText exactly equal to sourceText.',
                'Return exactly the same token count and token order as input.',
                'Fill cue romanization and every token romanization with readable Latin-script pronunciation.',
                'Use unknown for dialect when it cannot be detected.',
            ],
            'cues' => array_map(
                fn (array $cue): array => [
                    ...Arr::only($cue, ['cueId', 'index', 'sourceText']),
                    'tokens' => array_map(
                        fn (array $token): array => Arr::only($token, ['index', 'text']),
                        array_values(is_array($cue['tokens'] ?? null) ? $cue['tokens'] : []),
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
            ],
            'cue' => Arr::only($cue, ['cueId', 'index', 'sourceText', 'romanization']),
            'requestedToken' => Arr::only($token, ['index', 'text', 'normalizedText', 'romanization']),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function validatedCueResult(array $output, array $sourceCues): CueEnrichmentResult
    {
        $dialect = $this->cleanString($output['dialect'] ?? null) ?? 'unknown';
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

        $enrichedCues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $outputCue = $outputCues[$position] ?? null;

            if (! is_array($outputCue)) {
                $this->failInvalidOutput('invalid_cue', ['cue_position' => $position]);
            }

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
                'tokens' => $this->tokens($outputCue['tokens'] ?? null, (int) $sourceCue['index']),
            ];

            $romanization = $this->cleanString($outputCue['romanization'] ?? null);

            if ($romanization !== null) {
                $enrichedCue['romanization'] = $romanization;
            }

            $enrichedCues[] = $enrichedCue;
        }

        return new CueEnrichmentResult($enrichedCues, $dialect);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function romanizedResult(array $sourceCues, CueEnrichmentResult $result): CueEnrichmentResult
    {
        $cues = [];

        foreach ($sourceCues as $position => $sourceCue) {
            $romanizedCue = $result->cues[$position] ?? null;

            if (! is_array($romanizedCue)) {
                $this->failInvalidOutput('invalid_cue', ['cue_position' => $position]);
            }

            $cueRomanization = $this->cleanString($romanizedCue['romanization'] ?? null);

            if ($cueRomanization === null) {
                $this->failInvalidOutput('missing_romanization', [
                    'cue_index' => $sourceCue['index'] ?? $position,
                ]);
            }

            $sourceTokens = array_values(is_array($sourceCue['tokens'] ?? null) ? $sourceCue['tokens'] : []);
            $romanizedTokens = array_values(is_array($romanizedCue['tokens'] ?? null) ? $romanizedCue['tokens'] : []);

            if (count($romanizedTokens) !== count($sourceTokens)) {
                $this->failInvalidOutput('token_count_mismatch', [
                    'expected_count' => count($sourceTokens),
                    'actual_count' => count($romanizedTokens),
                ]);
            }

            $tokens = [];

            foreach ($sourceTokens as $tokenPosition => $sourceToken) {
                $romanizedToken = $romanizedTokens[$tokenPosition] ?? null;

                if (! is_array($romanizedToken)) {
                    $this->failInvalidOutput('invalid_token', [
                        'cue_index' => $sourceCue['index'] ?? $position,
                        'token_position' => $tokenPosition,
                    ]);
                }

                $tokenText = $this->cleanString($sourceToken['text'] ?? null);

                if ($tokenText === null || ($romanizedToken['text'] ?? null) !== $tokenText) {
                    $this->failInvalidOutput('token_identity_mismatch', [
                        'cue_index' => $sourceCue['index'] ?? $position,
                        'token_position' => $tokenPosition,
                    ]);
                }

                $tokenRomanization = $this->cleanString($romanizedToken['romanization'] ?? null);

                if ($tokenRomanization === null) {
                    $this->failInvalidOutput('missing_token_romanization', [
                        'cue_index' => $sourceCue['index'] ?? $position,
                        'token_position' => $tokenPosition,
                    ]);
                }

                $token = [
                    'index' => (int) $sourceToken['index'],
                    'text' => $tokenText,
                ];

                if (is_string($sourceToken['normalizedText'] ?? null) && trim($sourceToken['normalizedText']) !== '') {
                    $token['normalizedText'] = trim($sourceToken['normalizedText']);
                }

                $token['romanization'] = $tokenRomanization;
                $tokens[] = $token;
            }

            $cues[] = [
                ...$sourceCue,
                'translatedText' => (string) $sourceCue['sourceText'],
                'romanization' => $cueRomanization,
                'tokens' => $tokens,
            ];
        }

        return new CueEnrichmentResult($cues, $result->sourceDialect);
    }

    /**
     * @param  array<string, mixed>  $sourceCue
     * @param  array<string, mixed>  $outputCue
     */
    private function validateCueIdentity(array $sourceCue, array $outputCue, int $position): void
    {
        foreach (['cueId', 'sourceText'] as $field) {
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
     * @return array<int, array<string, mixed>>
     */
    private function tokens(mixed $tokens, int $cueIndex): array
    {
        if (! is_array($tokens)) {
            $this->failInvalidOutput('invalid_tokens', ['cue_index' => $cueIndex]);
        }

        $normalized = [];

        foreach (array_values($tokens) as $position => $token) {
            if (! is_array($token)) {
                $this->failInvalidOutput('invalid_token', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            if (! is_int($token['index'] ?? null) || $token['index'] < 0) {
                $this->failInvalidOutput('invalid_token_index', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $text = $this->cleanString($token['text'] ?? null);

            if ($text === null) {
                $this->failInvalidOutput('invalid_token_text', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $normalizedToken = [
                'index' => (int) $token['index'],
                'text' => $text,
            ];

            foreach (['normalizedText', 'lemma', 'root', 'partOfSpeech', 'translation', 'gloss', 'romanization', 'usageNote'] as $field) {
                $value = $this->cleanString($token[$field] ?? null);

                if ($value !== null) {
                    $normalizedToken[$field] = $value;
                }
            }

            $normalized[] = $normalizedToken;
        }

        return $normalized;
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
            'normalizedText' => $this->cleanString($sourceToken['normalizedText'] ?? null)
                ?? $this->cleanString($outputToken['normalizedText'] ?? null)
                ?? $this->normalizeTokenText($tokenText),
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
        $apiKey = config('ai.providers.'.Lab::OpenAI->value.'.key');

        if (! $agentClass::isFaked() && (! is_string($apiKey) || $apiKey === '')) {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle enrichment provider is not configured.', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model' => $this->model(),
            ]);
        }
    }

    private function model(): string
    {
        return (string) config(
            'ai.providers.'.Lab::OpenAI->value.'.models.enrichment.default',
            config('ai.providers.'.Lab::OpenAI->value.'.models.text.default', 'gpt-4o-mini'),
        );
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $cleaned === '' ? null : $cleaned;
    }

    private function normalizeTokenText(string $value): string
    {
        $normalized = $this->cleanString($value) ?? $value;

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
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
