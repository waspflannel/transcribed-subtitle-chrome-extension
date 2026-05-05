<?php

namespace App\Services\TranslationAnalysis;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use JsonException;
use Laravel\Ai\Enums\Lab;
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

        $sourceCues = array_values($cues);

        $this->ensureProviderConfigured();

        $enrichedCues = [];
        $dialect = 'unknown';
        $batchSize = max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));

        foreach (array_chunk($sourceCues, $batchSize) as $batch) {
            $result = $this->enrichBatchWithFallback($batch, $sourceLanguage, $targetLanguage);
            array_push($enrichedCues, ...$result->cues);

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult($enrichedCues, $dialect);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function enrichBatchWithFallback(array $sourceCues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        try {
            return $this->enrichBatch($sourceCues, $sourceLanguage, $targetLanguage);
        } catch (SubtitleProcessingException $exception) {
            if (! $this->shouldSplitBatch($exception, $sourceCues)) {
                throw $exception;
            }

            logger()->warning('backend.enrichment_batch_split_retry', [
                'reason' => $exception->context['reason'] ?? null,
                'cue_count' => count($sourceCues),
                'cue_indexes' => array_column($sourceCues, 'index'),
            ]);

            return $this->enrichSplitBatch($sourceCues, $sourceLanguage, $targetLanguage);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function enrichSplitBatch(array $sourceCues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        $enrichedCues = [];
        $dialect = 'unknown';
        $splitSize = (int) ceil(count($sourceCues) / 2);

        foreach (array_chunk($sourceCues, $splitSize) as $batch) {
            $result = $this->enrichBatchWithFallback($batch, $sourceLanguage, $targetLanguage);
            array_push($enrichedCues, ...$result->cues);

            if ($dialect === 'unknown' && $result->sourceDialect !== 'unknown') {
                $dialect = $result->sourceDialect;
            }
        }

        return new CueEnrichmentResult($enrichedCues, $dialect);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function shouldSplitBatch(SubtitleProcessingException $exception, array $sourceCues): bool
    {
        return $exception->publicCode === 'enrichment_failed'
            && count($sourceCues) > 1
            && (
                is_string($exception->context['reason'] ?? null)
                || is_string($exception->context['exception'] ?? null)
            );
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function enrichBatch(array $sourceCues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        try {
            $response = CueEnrichmentAgent::make()->prompt($this->prompt($sourceCues, $sourceLanguage, $targetLanguage));
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::enrichmentFailed(
                'Subtitle enrichment failed.',
                [
                    'provider' => Lab::OpenAI->value,
                    'adapter' => 'laravel-ai-sdk',
                    'model' => $this->model(),
                    'exception' => $exception::class,
                ],
                $exception,
            );
        }

        return $this->validatedResult($this->structuredResponse($response), $sourceCues);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourceCues
     *
     * @throws JsonException
     */
    private function prompt(array $sourceCues, string $sourceLanguage, string $targetLanguage): string
    {
        return json_encode([
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
            'instructions' => [
                'Return one enriched cue for each input cue in the same order.',
                'Do not change cueId, index, or sourceText.',
                'Translate into the target language.',
                'For Arabic source text, include token metadata when available.',
                'Use unknown for dialect when it cannot be detected.',
            ],
            'cues' => array_map(
                fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'sourceText']),
                $sourceCues,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<int, array<string, mixed>>  $sourceCues
     */
    private function validatedResult(array $output, array $sourceCues): CueEnrichmentResult
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
                'index' => $token['index'],
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
     * @return array<string, mixed>
     */
    private function structuredResponse(mixed $response): array
    {
        if ($response instanceof Arrayable) {
            return $response->toArray();
        }

        $structured = $response->structured ?? null;

        if (is_array($structured)) {
            return $structured;
        }

        $this->failInvalidOutput('missing_structured_response');
    }

    private function ensureProviderConfigured(): void
    {
        $apiKey = config('ai.providers.'.Lab::OpenAI->value.'.key');

        if (! CueEnrichmentAgent::isFaked() && (! is_string($apiKey) || $apiKey === '')) {
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
