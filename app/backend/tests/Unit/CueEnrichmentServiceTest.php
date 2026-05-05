<?php

namespace Tests\Unit;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use RuntimeException;
use Tests\TestCase;

class CueEnrichmentServiceTest extends TestCase
{
    public function test_enriches_cues_with_translation_tokens_and_dialect(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'egyptian',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'marhaban bikum',
                        'translatedText' => 'Welcome everyone',
                        'romanization' => 'marhaban bikum',
                        'tokens' => [
                            [
                                'index' => 0,
                                'text' => 'marhaban',
                                'normalizedText' => 'marhaban',
                                'lemma' => 'marhaban',
                                'root' => null,
                                'partOfSpeech' => 'interjection',
                                'translation' => 'hello',
                                'gloss' => 'greeting',
                                'romanization' => 'marhaban',
                                'usageNote' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->enrich($this->sourceCues(), 'ar', 'en');

        $this->assertSame('egyptian', $result->sourceDialect);
        $this->assertSame('Welcome everyone', $result->cues[0]['translatedText']);
        $this->assertSame('marhaban bikum', $result->cues[0]['romanization']);
        $this->assertSame([
            'index' => 0,
            'text' => 'marhaban',
            'normalizedText' => 'marhaban',
            'lemma' => 'marhaban',
            'partOfSpeech' => 'interjection',
            'translation' => 'hello',
            'gloss' => 'greeting',
            'romanization' => 'marhaban',
        ], $result->cues[0]['tokens'][0]);

        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"targetLanguage":"en"')
                && $prompt->contains('"cueId":"cue-0001"')
                && $prompt->contains('"sourceText":"marhaban bikum"'),
        );
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
            $this->provider()->enrich($this->sourceCues(), 'ar', 'en');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('cue_identity_mismatch', $exception->context['reason'] ?? null);
            $this->assertSame('sourceText', $exception->context['field'] ?? null);

            return;
        }

        $this->fail('Expected enrichment validation to fail.');
    }

    public function test_provider_failures_map_to_stable_public_errors(): void
    {
        CueEnrichmentAgent::fake(fn (): never => throw new RuntimeException('provider unavailable'))
            ->preventStrayPrompts();

        try {
            $this->provider()->enrich($this->sourceCues(), 'ar', 'en');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('laravel-ai-sdk', $exception->context['adapter'] ?? null);
            $this->assertSame(RuntimeException::class, $exception->context['exception'] ?? null);

            return;
        }

        $this->fail('Expected provider failure to map to a subtitle processing exception.');
    }

    public function test_enriches_cues_in_configured_batches(): void
    {
        config(['subtitles.enrichment.cue_batch_size' => 2]);

        CueEnrichmentAgent::fake([
            [
                'dialect' => 'egyptian',
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
        ], 'ar', 'en');

        $this->assertSame('egyptian', $result->sourceDialect);
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

    public function test_splits_invalid_multi_cue_batches_and_preserves_order(): void
    {
        config(['subtitles.enrichment.cue_batch_size' => 2]);

        CueEnrichmentAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    $this->outputCue('cue-0001', 0, 'source one'),
                ],
            ],
            [
                'dialect' => 'egyptian',
                'cues' => [
                    $this->outputCue('cue-0001', 0, 'source one'),
                ],
            ],
            [
                'dialect' => 'unknown',
                'cues' => [
                    $this->outputCue('cue-0002', 1, 'source two'),
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->enrich([
            $this->sourceCue('cue-0001', 0, 'source one'),
            $this->sourceCue('cue-0002', 1, 'source two'),
        ], 'ar', 'en');

        $this->assertSame('egyptian', $result->sourceDialect);
        $this->assertSame(['cue-0001', 'cue-0002'], array_column($result->cues, 'cueId'));
    }

    public function test_splits_multi_cue_batches_after_provider_timeout(): void
    {
        config(['subtitles.enrichment.cue_batch_size' => 2]);
        $calls = 0;

        CueEnrichmentAgent::fake(function (string $prompt) use (&$calls): array {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('provider timed out');
            }

            $input = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);

            return [
                'dialect' => 'unknown',
                'cues' => array_map(
                    fn (array $cue): array => $this->outputCue($cue['cueId'], $cue['index'], $cue['sourceText']),
                    $input['cues'],
                ),
            ];
        })->preventStrayPrompts();

        $result = $this->provider()->enrich([
            $this->sourceCue('cue-0001', 0, 'source one'),
            $this->sourceCue('cue-0002', 1, 'source two'),
        ], 'ar', 'en');

        $this->assertSame(['cue-0001', 'cue-0002'], array_column($result->cues, 'cueId'));
        $this->assertSame(3, $calls);
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
            $this->sourceCue('cue-0001', 0, 'marhaban bikum'),
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
