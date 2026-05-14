<?php

namespace Tests\Unit;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

class CueEnrichmentServiceTest extends TestCase
{
    public function test_tokenizes_cues_with_agent_boundaries(): void
    {
        $sourceText = '違う姿違う形なの';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => $sourceText,
                        'tokens' => $this->generatedTokens($sourceText, ['違う', '姿', '違う', '形なの']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame(['違う', '姿', '違う', '形なの'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame($sourceText, $result->cues[0]['translatedText']);

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'sourceLanguage') === 'jpn'
                && data_get($this->promptInput($prompt), 'cues.0.sourceText') === $sourceText,
        );
    }

    public function test_tokenization_validation_failure_fails_generation(): void
    {
        $sourceText = 'Hola a todos';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($sourceText, ['missing']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->provider()->tokenize([
                $this->sourceCue('cue-0001', 0, $sourceText),
            ], 'jpn'),
            'token_text_not_in_source',
        );

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && ! array_key_exists('qualityFailures', $this->promptInput($prompt)),
        );
    }

    public function test_tokenization_fails_batch_when_any_cue_has_invalid_tokens(): void
    {
        $validSourceText = 'Hola amiga';
        $failedSourceText = 'good morning';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($validSourceText, ['Hola', 'amiga']),
                    ],
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'tokens' => $this->generatedTokens($failedSourceText, ['evening']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->provider()->tokenize([
                $this->sourceCue('cue-0001', 0, $validSourceText),
                $this->sourceCue('cue-0002', 1, $failedSourceText),
            ], 'jpn'),
            'token_text_not_in_source',
        );

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.default')
                && $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'cues.0.nextCueText') === 'good morning'
                && data_get($this->promptInput($prompt), 'cues.1.previousCueText') === 'Hola amiga',
        );
    }

    public function test_tokenization_count_mismatch_fails_generation(): void
    {
        $firstSourceText = 'Bonjour a tous';
        $secondSourceText = 'Je suis tres heureux';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($firstSourceText, ['Bonjour', 'a tous']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->provider()->tokenize([
                $this->sourceCue('cue-0001', 0, $firstSourceText),
                $this->sourceCue('cue-0002', 1, $secondSourceText),
            ], 'fra'),
            'cue_count_mismatch',
        );

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"cueId":"cue-0001"')
                && $this->promptInputHasNoInstructions($prompt)
                && $prompt->contains('"cueId":"cue-0002"'),
        );
    }

    public function test_tokenization_accepts_valid_agent_output(): void
    {
        $sourceText = '違う姿違う形なの';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => $sourceText,
                        'tokens' => $this->generatedTokens($sourceText, ['違う', '姿', '違う', '形なの']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame(['違う', '姿', '違う', '形なの'], array_column($result->cues[0]['tokens'], 'text'));

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && ! array_key_exists('qualityFailures', $this->promptInput($prompt)),
        );
    }

    public function test_tokenization_prompt_uses_normalized_source_text_without_span_inputs(): void
    {
        $sourceText = "\u{3063} \u{3068} \u{3088} \u{FF09}\u{3002} \u{4F1D} \u{308F} \u{306A} \u{3044} \u{3084} \u{306A} \u{3093} \u{3066} \u{30BF} \u{30B2} \u{30C3}";

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($sourceText, [
                            "\u{3063}\u{3068}\u{3088}",
                            "\u{4F1D}\u{308F}\u{306A}\u{3044}",
                            "\u{3084}",
                            "\u{306A}\u{3093}\u{3066}",
                            "\u{30BF}\u{30B2}\u{30C3}",
                        ]),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame($sourceText, $result->cues[0]['sourceText']);
        $this->assertSame([
            "\u{3063}\u{3068}\u{3088}",
            "\u{4F1D}\u{308F}\u{306A}\u{3044}",
            "\u{3084}",
            "\u{306A}\u{3093}\u{3066}",
            "\u{30BF}\u{30B2}\u{30C3}",
        ], array_column($result->cues[0]['tokens'], 'text'));

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains($sourceText)
                && $this->promptInputHasNoInstructions($prompt)
                && ! $prompt->contains('tokenizationText')
                && ! $prompt->contains('sourceStart')
                && ! $prompt->contains('sourceEnd'),
        );
    }

    public function test_tokenization_changed_cue_identity_fails_generation(): void
    {
        $sourceText = '違う姿違う形なの';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'different-cue',
                        'index' => 0,
                        'sourceText' => $sourceText,
                        'tokens' => $this->generatedTokens($sourceText, ['違う']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->provider()->tokenize([$this->sourceCue('cue-0001', 0, $sourceText)], 'jpn'),
            'cue_identity_mismatch',
        );

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && ! array_key_exists('qualityFailures', $this->promptInput($prompt)),
        );
    }

    public function test_enriches_full_cues_with_translation_metadata_without_retokenizing(): void
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
                                'text' => 'a todos',
                                'gloss' => 'everyone',
                                'romanization' => null,
                                'usageNote' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->enrich($this->tokenizedSourceCues(), 'spa', 'fra');

        $this->assertSame('castilian', $result->sourceDialect);
        $this->assertSame('Bonjour a tous', $result->cues[0]['translatedText']);
        $this->assertSame('hola a todos', $result->cues[0]['romanization']);
        $this->assertSame(['hola', 'a todos'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame('hello', $result->cues[0]['tokens'][0]['gloss']);

        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'targetLanguage') === 'fra'
                && data_get($this->promptInput($prompt), 'includeRomanization') === true
                && data_get($this->promptInput($prompt), 'cues.0.tokens.1.text') === 'a todos',
        );
    }

    public function test_full_enrichment_rejects_tokenless_cues(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'castilian',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'bad tokenization',
                        'translatedText' => 'bad tokenization',
                        'tokens' => [],
                    ],
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'sourceText' => 'hola',
                        'translatedText' => 'hello',
                        'tokens' => [
                            ['index' => 0, 'text' => 'hola', 'gloss' => 'hello'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $tokenlessCue = [
            ...$this->sourceCue('cue-0001', 0, 'bad tokenization'),
            'translatedText' => 'bad tokenization',
            'tokens' => [],
        ];
        $validCue = [
            ...$this->sourceCue('cue-0002', 1, 'hola'),
            'translatedText' => 'hola',
            'tokens' => [
                ['index' => 0, 'text' => 'hola', 'normalizedText' => 'hola'],
            ],
        ];

        $this->assertProviderFailureReason(
            fn () => $this->provider()->enrich([$tokenlessCue, $validCue], 'spa', 'eng'),
            'missing_source_tokens',
        );
    }

    public function test_enrichment_omits_romanization_when_not_requested(): void
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
                            ['index' => 0, 'text' => 'hola', 'romanization' => 'o-la'],
                            ['index' => 1, 'text' => 'a todos', 'romanization' => 'a to-dos'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->enrich($this->tokenizedSourceCues(), 'spa', 'fra', includeRomanization: false);

        $this->assertArrayNotHasKey('romanization', $result->cues[0]);
        $this->assertArrayNotHasKey('romanization', $result->cues[0]['tokens'][0]);
    }

    public function test_enrichment_rejects_changed_token_boundaries(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'hola a todos',
                        'translatedText' => 'Welcome everyone',
                        'tokens' => [
                            ['index' => 0, 'text' => 'hola'],
                            ['index' => 1, 'text' => 'todos'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->provider()->enrich($this->tokenizedSourceCues(), 'spa', 'fra'),
            'token_identity_mismatch',
        );
    }

    public function test_romanizes_transcript_first_non_latin_cues_without_changing_tokens(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => '私は日本語を勉強しています',
                        'translatedText' => '私は日本語を勉強しています',
                        'romanization' => 'watashi wa nihongo o benkyo shite imasu',
                        'tokens' => [
                            ['index' => 0, 'text' => '私', 'romanization' => 'watashi'],
                            ['index' => 1, 'text' => 'は', 'romanization' => 'wa'],
                            ['index' => 2, 'text' => '日本語', 'romanization' => 'nihongo'],
                            ['index' => 3, 'text' => 'を', 'romanization' => 'o'],
                            ['index' => 4, 'text' => '勉強しています', 'romanization' => 'benkyo shite imasu'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->sourceCue('cue-0001', 0, '私は日本語を勉強しています'),
            'translatedText' => '私は日本語を勉強しています',
            'tokens' => [
                ['index' => 0, 'text' => '私', 'normalizedText' => '私'],
                ['index' => 1, 'text' => 'は', 'normalizedText' => 'は'],
                ['index' => 2, 'text' => '日本語', 'normalizedText' => '日本語'],
                ['index' => 3, 'text' => 'を', 'normalizedText' => 'を'],
                ['index' => 4, 'text' => '勉強しています', 'normalizedText' => '勉強しています'],
            ],
        ];

        $result = $this->provider()->romanize([$sourceCue], 'jpn');

        $this->assertSame('watashi wa nihongo o benkyo shite imasu', $result->cues[0]['romanization']);
        $this->assertSame(['私', 'は', '日本語', 'を', '勉強しています'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame('nihongo', $result->cues[0]['tokens'][2]['romanization']);
        $this->assertArrayNotHasKey('gloss', $result->cues[0]['tokens'][2]);

        CueRomanizationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'sourceLanguage') === 'jpn'
                && data_get($this->promptInput($prompt), 'targetLanguage') === 'jpn'
                && data_get($this->promptInput($prompt), 'cues.0.tokens.2.text') === '日本語',
        );
    }

    public function test_romanization_rejects_tokenless_cues(): void
    {
        $tokenlessText = 'bad tokenization';

        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => $tokenlessText,
                        'translatedText' => $tokenlessText,
                        'romanization' => 'kakiitemita',
                        'tokens' => [],
                    ],
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'sourceText' => '日本語',
                        'translatedText' => '日本語',
                        'romanization' => 'nihongo',
                        'tokens' => [
                            ['index' => 0, 'text' => '日本語', 'romanization' => 'nihongo'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $tokenlessCue = [
            ...$this->sourceCue('cue-0001', 0, $tokenlessText),
            'translatedText' => $tokenlessText,
            'tokens' => [],
        ];
        $validCue = [
            ...$this->sourceCue('cue-0002', 1, '日本語'),
            'translatedText' => '日本語',
            'tokens' => [
                ['index' => 0, 'text' => '日本語', 'normalizedText' => '日本語'],
            ],
        ];

        $this->assertProviderFailureReason(
            fn () => $this->provider()->romanize([$tokenlessCue, $validCue], 'jpn'),
            'missing_source_tokens',
        );
    }

    public function test_romanization_rejects_changed_token_boundaries(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => '日本語勉強',
                        'translatedText' => '日本語勉強',
                        'romanization' => 'nihongo benkyo',
                        'tokens' => [
                            ['index' => 0, 'text' => '日', 'romanization' => 'ni'],
                            ['index' => 1, 'text' => '本語勉強', 'romanization' => 'hongo benkyo'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->sourceCue('cue-0001', 0, '日本語勉強'),
            'translatedText' => '日本語勉強',
            'tokens' => [
                ['index' => 0, 'text' => '日本語', 'normalizedText' => '日本語'],
                ['index' => 1, 'text' => '勉強', 'normalizedText' => '勉強'],
            ],
        ];

        $this->assertProviderFailureReason(
            fn () => $this->provider()->romanize([$sourceCue], 'jpn'),
            'token_identity_mismatch',
        );
    }

    public function test_romanization_rejects_changed_token_count(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => '日本語勉強',
                        'translatedText' => '日本語勉強',
                        'romanization' => 'nihongo benkyo',
                        'tokens' => [
                            ['index' => 0, 'text' => '日本語', 'romanization' => 'nihongo'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->sourceCue('cue-0001', 0, '日本語勉強'),
            'translatedText' => '日本語勉強',
            'tokens' => [
                ['index' => 0, 'text' => '日本語', 'normalizedText' => '日本語'],
                ['index' => 1, 'text' => '勉強', 'normalizedText' => '勉強'],
            ],
        ];

        $this->assertProviderFailureReason(
            fn () => $this->provider()->romanize([$sourceCue], 'jpn'),
            'token_count_mismatch',
        );
    }

    public function test_enriches_one_requested_token_for_on_demand_word_cards(): void
    {
        LearningTokenCardAgent::fake([
            [
                'token' => [
                    'index' => 0,
                    'text' => 'Hola',
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
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'cue.sourceText') === 'Hola a todos'
                && data_get($this->promptInput($prompt), 'requestedToken.text') === 'Hola',
        );
    }

    public function test_provider_request_errors_map_to_stable_public_errors(): void
    {
        $calls = 0;

        CueEnrichmentAgent::fake(function () use (&$calls): never {
            $calls++;

            throw new RequestException(new Response(new PsrResponse(400)));
        })->preventStrayPrompts();

        try {
            $this->provider()->enrich($this->tokenizedSourceCues(), 'eng', 'eng');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame(RequestException::class, $exception->context['exception'] ?? null);
            $this->assertSame(400, $exception->context['status'] ?? null);
            $this->assertSame(1, $calls);

            return;
        }

        $this->fail('Expected provider request failure to map to a subtitle processing exception.');
    }

    public function test_provider_rate_limits_map_to_stable_rate_limited_error(): void
    {
        CueTokenizationAgent::fake(
            fn (): never => throw RateLimitedException::forProvider('openai', 429),
        )->preventStrayPrompts();

        try {
            $this->provider()->tokenize([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('rate_limited', $exception->publicCode);
            $this->assertSame(429, $exception->status);
            $this->assertSame(RateLimitedException::class, $exception->context['exception'] ?? null);
            $this->assertSame(CueTokenizationAgent::class, $exception->context['agent'] ?? null);

            return;
        }

        $this->fail('Expected provider rate limit to map to a subtitle processing exception.');
    }

    public function test_provider_failures_map_to_stable_public_errors(): void
    {
        CueTokenizationAgent::fake(fn (): never => throw new RuntimeException('provider unavailable'))
            ->preventStrayPrompts();

        try {
            $this->provider()->tokenize([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
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
        return new LaravelAiTranslationAnalysisProvider(new LearningTokenOutputValidator);
    }

    /**
     * @return array<string, mixed>
     */
    private function promptInput(mixed $prompt): array
    {
        $input = json_decode($prompt->prompt, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($input);

        return $input;
    }

    private function promptInputHasNoInstructions(mixed $prompt): bool
    {
        return ! array_key_exists('instructions', $this->promptInput($prompt));
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array{index: int, text: string}>
     */
    private function generatedTokens(string $sourceText, array $texts): array
    {
        return array_map(
            fn (string $text, int $index): array => [
                'index' => $index,
                'text' => $text,
            ],
            array_values($texts),
            array_keys(array_values($texts)),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokenizedSourceCues(): array
    {
        return [
            [
                ...$this->sourceCue('cue-0001', 0, 'hola a todos'),
                'translatedText' => 'hola a todos',
                'tokens' => [
                    ['index' => 0, 'text' => 'hola', 'normalizedText' => 'hola'],
                    ['index' => 1, 'text' => 'a todos', 'normalizedText' => 'a todos'],
                ],
            ],
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

    private function assertProviderFailureReason(callable $callback, string $reason): void
    {
        try {
            $callback();
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame($reason, $exception->context['reason'] ?? null);

            return;
        }

        $this->fail("Expected provider output to fail with {$reason}.");
    }
}
