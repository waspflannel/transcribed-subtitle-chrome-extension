<?php

namespace Tests\Unit;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

class CueEnrichmentServiceTest extends TestCase
{
    public function test_enriches_full_cues_with_translation_word_cards_and_dialect(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'castilian',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'hola a todos',
                        'translatedText' => 'Bonjour a tous',
                        'romanization' => 'hola a todos',
                        'tokens' => [
                            [
                                'index' => 0,
                                'text' => 'hola',
                                'gloss' => 'hello',
                                'romanization' => 'o-la',
                                'usageNote' => 'Common greeting.',
                            ],
                            [
                                'index' => 1,
                                'text' => 'todos',
                                'gloss' => 'everyone',
                                'romanization' => null,
                                'usageNote' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->enrich($this->sourceCues(), 'spa', 'fra');

        $this->assertSame('castilian', $result->sourceDialect);
        $this->assertSame('Bonjour a tous', $result->cues[0]['translatedText']);
        $this->assertSame('hola a todos', $result->cues[0]['romanization']);
        $this->assertSame([
            'index' => 0,
            'text' => 'hola',
            'gloss' => 'hello',
            'romanization' => 'o-la',
            'usageNote' => 'Common greeting.',
        ], $result->cues[0]['tokens'][0]);

        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"targetLanguage":"fra"')
                && $prompt->contains('"targetLanguageName":"French"')
                && $prompt->contains('"sourceLanguageName":"Spanish"')
                && $prompt->contains('"cueId":"cue-0001"')
                && $prompt->contains('Return one lightweight token'),
        );
    }

    public function test_romanizes_transcript_first_non_latin_cues_without_word_card_metadata(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'مرحبا بكم',
                        'translatedText' => 'مرحبا بكم',
                        'romanization' => 'marhaban bikum',
                        'tokens' => [
                            [
                                'index' => 0,
                                'text' => 'مرحبا',
                                'romanization' => 'marhaban',
                            ],
                            [
                                'index' => 1,
                                'text' => 'بكم',
                                'romanization' => 'bikum',
                            ],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->sourceCue('cue-0001', 0, 'مرحبا بكم'),
            'translatedText' => 'مرحبا بكم',
            'tokens' => [
                ['index' => 0, 'text' => 'مرحبا', 'normalizedText' => 'مرحبا'],
                ['index' => 1, 'text' => 'بكم', 'normalizedText' => 'بكم'],
            ],
        ];

        $result = $this->provider()->romanize([$sourceCue], 'ara');

        $this->assertSame('مرحبا بكم', $result->cues[0]['translatedText']);
        $this->assertSame('marhaban bikum', $result->cues[0]['romanization']);
        $this->assertSame([
            'index' => 0,
            'text' => 'مرحبا',
            'normalizedText' => 'مرحبا',
            'romanization' => 'marhaban',
        ], $result->cues[0]['tokens'][0]);
        $this->assertArrayNotHasKey('gloss', $result->cues[0]['tokens'][0]);

        CueRomanizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('Romanize subtitle cues written in non-Latin scripts')
                && $prompt->contains('Do not translate')
                && $prompt->contains('"text":"مرحبا"'),
        );
    }

    public function test_enriches_one_requested_token_for_on_demand_word_cards(): void
    {
        LearningTokenCardAgent::fake([
            [
                'token' => [
                    'index' => 0,
                    'text' => 'Hola',
                    'normalizedText' => 'hola',
                    'lemma' => 'hola',
                    'root' => null,
                    'partOfSpeech' => null,
                    'translation' => null,
                    'gloss' => 'hello',
                    'romanization' => null,
                    'usageNote' => 'Common greeting.',
                ],
            ],
        ])->preventStrayPrompts();

        $token = $this->provider()->enrichToken(
            cue: $this->sourceCue('cue-0001', 0, 'Hola a todos'),
            token: ['index' => 0, 'text' => 'Hola', 'normalizedText' => 'hola'],
            sourceLanguage: 'spa',
            targetLanguage: 'jpn',
        );

        $this->assertSame([
            'index' => 0,
            'text' => 'Hola',
            'normalizedText' => 'hola',
            'lemma' => 'hola',
            'gloss' => 'hello',
            'usageNote' => 'Common greeting.',
        ], $token);

        LearningTokenCardAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"requestedToken":{"index":0,"text":"Hola","normalizedText":"hola"}')
                && $prompt->contains('"targetLanguageName":"Japanese"')
                && $prompt->contains('Return exactly one token object'),
        );
    }

    public function test_enriches_cues_in_configured_batches_without_recursive_split_retry(): void
    {
        config(['subtitles.enrichment.cue_batch_size' => 2]);

        CueEnrichmentAgent::fake([
            [
                'dialect' => 'castilian',
                'cues' => [
                    $this->outputCue('cue-0001', 0, 'source one'),
                    $this->outputCue('cue-0002', 1, 'source two'),
                ],
            ],
            [
                'dialect' => 'unknown',
                'cues' => [
                    $this->outputCue('cue-0003', 2, 'source three'),
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->enrich([
            $this->sourceCue('cue-0001', 0, 'source one'),
            $this->sourceCue('cue-0002', 1, 'source two'),
            $this->sourceCue('cue-0003', 2, 'source three'),
        ], 'spa', 'fra');

        $this->assertSame('castilian', $result->sourceDialect);
        $this->assertSame(['cue-0001', 'cue-0002', 'cue-0003'], array_column($result->cues, 'cueId'));

        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"cueId":"cue-0001"')
                && $prompt->contains('"cueId":"cue-0002"')
                && ! $prompt->contains('"cueId":"cue-0003"'),
        );
        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"cueId":"cue-0003"')
                && ! $prompt->contains('"cueId":"cue-0001"'),
        );
    }

    public function test_invalid_full_batch_fails_without_split_retry(): void
    {
        config(['subtitles.enrichment.cue_batch_size' => 2]);

        CueEnrichmentAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    $this->outputCue('cue-0001', 0, 'source one'),
                ],
            ],
        ])->preventStrayPrompts();

        try {
            $this->provider()->enrich([
                $this->sourceCue('cue-0001', 0, 'source one'),
                $this->sourceCue('cue-0002', 1, 'source two'),
            ], 'spa', 'fra');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('cue_count_mismatch', $exception->context['reason'] ?? null);

            return;
        }

        $this->fail('Expected invalid batch to fail without recursive splitting.');
    }

    public function test_rejects_cue_identity_mismatches(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'changed source',
                        'translatedText' => 'Welcome everyone',
                        'tokens' => [],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        try {
            $this->provider()->enrich($this->sourceCues(), 'spa', 'fra');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('cue_identity_mismatch', $exception->context['reason'] ?? null);
            $this->assertSame('sourceText', $exception->context['field'] ?? null);

            return;
        }

        $this->fail('Expected enrichment validation to fail.');
    }

    public function test_provider_request_errors_map_without_split_retry(): void
    {
        $calls = 0;

        CueEnrichmentAgent::fake(function () use (&$calls): never {
            $calls++;

            throw new RequestException(new Response(new PsrResponse(400)));
        })->preventStrayPrompts();

        try {
            $this->provider()->enrich([
                $this->sourceCue('cue-0001', 0, 'source one'),
                $this->sourceCue('cue-0002', 1, 'source two'),
            ], 'eng', 'eng');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame(RequestException::class, $exception->context['exception'] ?? null);
            $this->assertSame(400, $exception->context['status'] ?? null);
            $this->assertSame(1, $calls);

            return;
        }

        $this->fail('Expected provider request failure to map without split retries.');
    }

    public function test_provider_rate_limits_map_to_stable_rate_limited_error(): void
    {
        CueEnrichmentAgent::fake(
            fn (): never => throw RateLimitedException::forProvider('openai', 429),
        )->preventStrayPrompts();

        try {
            $this->provider()->enrich($this->sourceCues(), 'spa', 'fra');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('rate_limited', $exception->publicCode);
            $this->assertSame(429, $exception->status);
            $this->assertSame(RateLimitedException::class, $exception->context['exception'] ?? null);

            return;
        }

        $this->fail('Expected provider rate limit to map to a subtitle processing exception.');
    }

    public function test_provider_failures_map_to_stable_public_errors(): void
    {
        CueEnrichmentAgent::fake(fn (): never => throw new RuntimeException('provider unavailable'))
            ->preventStrayPrompts();

        try {
            $this->provider()->enrich($this->sourceCues(), 'spa', 'fra');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('laravel-ai-sdk', $exception->context['adapter'] ?? null);
            $this->assertSame(RuntimeException::class, $exception->context['exception'] ?? null);

            return;
        }

        $this->fail('Expected provider failure to map to a subtitle processing exception.');
    }

    private function provider(): LaravelAiTranslationAnalysisProvider
    {
        return new LaravelAiTranslationAnalysisProvider;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceCues(): array
    {
        return [
            $this->sourceCue('cue-0001', 0, 'hola a todos'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceCue(string $cueId, int $index, string $sourceText): array
    {
        return [
            'cueId' => $cueId,
            'index' => $index,
            'startMs' => 500 + ($index * 2000),
            'endMs' => 2100 + ($index * 2000),
            'sourceText' => $sourceText,
            'translatedText' => '',
            'tokens' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outputCue(string $cueId, int $index, string $sourceText): array
    {
        return [
            'cueId' => $cueId,
            'index' => $index,
            'sourceText' => $sourceText,
            'translatedText' => 'Translated '.$sourceText,
            'tokens' => [],
        ];
    }
}
