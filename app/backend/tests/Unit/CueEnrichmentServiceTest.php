<?php

namespace Tests\Unit;

use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\CueTranslationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

class CueEnrichmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai.models.translation.default' => 'gpt-test-translation']);
    }

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

        $result = $this->tokenizeBatch([
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

    public function test_tokenization_validation_failure_falls_back_to_deterministic_tokens(): void
    {
        $sourceText = 'Hola a todos';
        $invalidCue = [
            'cueId' => 'cue-0001',
            'index' => 0,
            'tokens' => $this->generatedTokens($sourceText, ['missing']),
        ];

        // First attempt and one re-prompt both return invalid output.
        CueTokenizationAgent::fake([
            ['dialect' => 'unknown', 'cues' => [$invalidCue]],
            ['dialect' => 'unknown', 'cues' => [$invalidCue]],
        ]);

        $result = $this->tokenizeBatch([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame(['Hola', 'a', 'todos'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame([0, 1, 2], array_column($result->cues[0]['tokens'], 'index'));
        $this->assertSame($sourceText, $result->cues[0]['translatedText']);
    }

    public function test_tokenization_validation_failure_recovers_when_reprompt_succeeds(): void
    {
        $sourceText = 'Hola a todos';
        $invalidCue = [
            'cueId' => 'cue-0001',
            'index' => 0,
            'tokens' => $this->generatedTokens($sourceText, ['missing']),
        ];
        $validCue = [
            'cueId' => 'cue-0001',
            'index' => 0,
            'sourceText' => $sourceText,
            'tokens' => $this->generatedTokens($sourceText, ['Hola', 'a', 'todos']),
        ];

        CueTokenizationAgent::fake([
            ['dialect' => 'unknown', 'cues' => [$invalidCue]],
            ['dialect' => 'unknown', 'cues' => [$validCue]],
        ]);

        $result = $this->tokenizeBatch([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame(['Hola', 'a', 'todos'], array_column($result->cues[0]['tokens'], 'text'));
    }

    public function test_tokenization_degrades_single_invalid_cue_when_split_retry_bottoms_out(): void
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
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($validSourceText, ['Hola', 'amiga']),
                    ],
                ],
            ],
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'tokens' => $this->generatedTokens($failedSourceText, ['evening']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->tokenizeBatch([
            $this->sourceCue('cue-0001', 0, $validSourceText),
            $this->sourceCue('cue-0002', 1, $failedSourceText),
        ], 'jpn');

        // The healthy cue keeps agent-chosen boundaries; the bad cue degrades
        // to deterministic whitespace-split tokens instead of failing the job.
        $this->assertSame(['Hola', 'amiga'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame(['good', 'morning'], array_column($result->cues[1]['tokens'], 'text'));

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.default')
                && $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'cues.0.nextCueText') === 'good morning'
                && data_get($this->promptInput($prompt), 'cues.1.previousCueText') === 'Hola amiga',
        );
    }

    public function test_tokenization_retries_invalid_multi_cue_batches_at_smaller_size(): void
    {
        $firstSourceText = 'hello world';
        $secondSourceText = 'good morning';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($firstSourceText, ['hello', 'world']),
                    ],
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'tokens' => $this->generatedTokens($secondSourceText, ['good', 'evening']),
                    ],
                ],
            ],
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($firstSourceText, ['hello', 'world']),
                    ],
                ],
            ],
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'tokens' => $this->generatedTokens($secondSourceText, ['good', 'morning']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->tokenizeBatch([
            $this->sourceCue('cue-0001', 0, $firstSourceText),
            $this->sourceCue('cue-0002', 1, $secondSourceText),
        ], 'eng');

        $this->assertSame(['hello', 'world'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame(['good', 'morning'], array_column($result->cues[1]['tokens'], 'text'));
    }

    public function test_batch_agents_require_full_cue_context(): void
    {
        $cue = $this->sourceCue('cue-0001', 0, 'hola a todos');

        CueTokenizationAgent::fake([])->preventStrayPrompts();
        CueTranslationAgent::fake([])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->provider()->tokenizeCueBatch([$cue], [], 'spa'),
            'empty_context_cues',
        );
        $this->assertProviderFailureReason(
            fn () => $this->provider()->translateCueBatch([$cue], 'spa', 'eng', []),
            'empty_context_cues',
        );
    }

    public function test_tokenization_count_mismatch_degrades_failing_split_cue(): void
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
            [
                'dialect' => 'unknown',
                'cues' => [],
            ],
        ])->preventStrayPrompts();

        $result = $this->tokenizeBatch([
            $this->sourceCue('cue-0001', 0, $firstSourceText),
            $this->sourceCue('cue-0002', 1, $secondSourceText),
        ], 'fra');

        // The 2-source batch mismatches, splits; the cue that returns zero
        // output degrades to deterministic tokens rather than failing the job.
        $this->assertSame(['Bonjour', 'a tous'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame(['Je', 'suis', 'tres', 'heureux'], array_column($result->cues[1]['tokens'], 'text'));

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

        $result = $this->tokenizeBatch([
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

        $result = $this->tokenizeBatch([
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

    public function test_tokenization_changed_cue_identity_degrades_to_deterministic_tokens(): void
    {
        $sourceText = '違う姿違う形なの';

        $invalidCue = [
            'cueId' => 'different-cue',
            'index' => 0,
            'sourceText' => $sourceText,
            'tokens' => $this->generatedTokens($sourceText, ['違う']),
        ];

        // First attempt and one top-level re-prompt both return a mismatched
        // cue id; the cue then degrades to per-character deterministic tokens.
        CueTokenizationAgent::fake([
            ['dialect' => 'unknown', 'cues' => [$invalidCue]],
            ['dialect' => 'unknown', 'cues' => [$invalidCue]],
        ])->preventStrayPrompts();

        $result = $this->tokenizeBatch([$this->sourceCue('cue-0001', 0, $sourceText)], 'jpn');

        $this->assertSame(
            ['違', 'う', '姿', '違', 'う', '形', 'な', 'の'],
            array_column($result->cues[0]['tokens'], 'text'),
        );

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && ! array_key_exists('qualityFailures', $this->promptInput($prompt)),
        );
    }

    public function test_enriches_full_cues_with_word_card_metadata_without_retokenizing(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'castilian',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'hola a todos',
                        'translatedText' => 'hola a todos',
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

        $result = $this->enrichBatch($this->tokenizedSourceCues(), 'spa', 'fra');

        $this->assertSame('castilian', $result->sourceDialect);
        $this->assertSame('hola a todos', $result->cues[0]['translatedText']);
        $this->assertSame('hola a todos', $result->cues[0]['romanization']);
        $this->assertSame(['hola', 'a todos'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame('hello', $result->cues[0]['tokens'][0]['gloss']);

        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'targetLanguage') === 'fra'
                && data_get($this->promptInput($prompt), 'includeRomanization') === true
                && data_get($this->promptInput($prompt), 'cues.0.translatedText') === 'hola a todos'
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
            fn () => $this->enrichBatch([$tokenlessCue, $validCue], 'spa', 'eng'),
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
                        'translatedText' => 'hola a todos',
                        'romanization' => 'hola a todos',
                        'tokens' => [
                            ['index' => 0, 'text' => 'hola', 'romanization' => 'o-la'],
                            ['index' => 1, 'text' => 'a todos', 'romanization' => 'a to-dos'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->enrichBatch($this->tokenizedSourceCues(), 'spa', 'fra', includeRomanization: false);

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
                        'translatedText' => 'hola a todos',
                        'tokens' => [
                            ['index' => 0, 'text' => 'hola'],
                            ['index' => 1, 'text' => 'todos'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->enrichBatch($this->tokenizedSourceCues(), 'spa', 'fra'),
            'token_identity_mismatch',
        );
    }

    public function test_translates_cues_without_changing_tokens_or_romanization(): void
    {
        CueTranslationAgent::fake([
            [
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'translatedText' => 'bonjour a tous',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->tokenizedSourceCues()[0],
            'romanization' => 'o-la a to-dos',
            'tokens' => [
                ['index' => 0, 'text' => 'hola', 'normalizedText' => 'hola', 'romanization' => 'o-la'],
                ['index' => 1, 'text' => 'a todos', 'normalizedText' => 'a todos'],
            ],
        ];

        $result = $this->translateBatch([$sourceCue], 'spa', 'fra');

        $this->assertSame('bonjour a tous', $result->cues[0]['translatedText']);
        $this->assertSame('o-la a to-dos', $result->cues[0]['romanization']);
        $this->assertSame(['hola', 'a todos'], array_column($result->cues[0]['tokens'], 'text'));

        CueTranslationAgent::assertPrompted(
            fn ($prompt): bool => $this->promptInputHasNoInstructions($prompt)
                && data_get($this->promptInput($prompt), 'targetLanguage') === 'fra'
                && data_get($this->promptInput($prompt), 'cues.0.sourceText') === 'hola a todos'
                && ! array_key_exists('tokens', $this->promptInput($prompt)['cues'][0]),
        );
    }

    public function test_translation_prompt_includes_neighboring_cue_context_without_tokens(): void
    {
        CueTranslationAgent::fake([
            [
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'translatedText' => 'the song begins',
                    ],
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'translatedText' => 'contextual meaning',
                    ],
                    [
                        'cueId' => 'cue-0003',
                        'index' => 2,
                        'translatedText' => 'the crowd answers',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCues = [
            [
                ...$this->sourceCue('cue-0001', 0, 'the song begins'),
                'tokens' => [['index' => 0, 'text' => 'the song begins']],
            ],
            [
                ...$this->sourceCue('cue-0002', 1, 'ambiguous idiom'),
                'tokens' => [['index' => 0, 'text' => 'ambiguous idiom']],
            ],
            [
                ...$this->sourceCue('cue-0003', 2, 'the crowd answers'),
                'tokens' => [['index' => 0, 'text' => 'the crowd answers']],
            ],
        ];

        $this->translateBatch($sourceCues, 'ara', 'eng');

        CueTranslationAgent::assertPrompted(function ($prompt): bool {
            $input = $this->promptInput($prompt);

            return $this->promptInputHasNoInstructions($prompt)
                && data_get($input, 'sourceLanguage') === 'ara'
                && data_get($input, 'targetLanguage') === 'eng'
                && array_key_exists('previousCueText', $input['cues'][0])
                && data_get($input, 'cues.0.previousCueText') === null
                && data_get($input, 'cues.0.nextCueText') === 'ambiguous idiom'
                && data_get($input, 'cues.1.previousCueText') === 'the song begins'
                && data_get($input, 'cues.1.nextCueText') === 'the crowd answers'
                && data_get($input, 'cues.2.previousCueText') === 'ambiguous idiom'
                && data_get($input, 'cues.2.nextCueText') === null
                && ! array_key_exists('tokens', $input['cues'][1]);
        });
    }

    public function test_translation_matches_cues_by_id_when_model_reorders_output(): void
    {
        // The model returned the two cues in reversed order. Matching by cueId
        // (not array position) keeps each cue's translation correct instead of
        // failing with a cue identity mismatch.
        CueTranslationAgent::fake([
            [
                'cues' => [
                    ['cueId' => 'cue-0002', 'index' => 1, 'translatedText' => 'world'],
                    ['cueId' => 'cue-0001', 'index' => 0, 'translatedText' => 'hello'],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCues = [
            $this->sourceCue('cue-0001', 0, 'hola'),
            $this->sourceCue('cue-0002', 1, 'mundo'),
        ];

        $result = $this->translateBatch($sourceCues, 'spa', 'eng');

        $this->assertSame(['cue-0001', 'cue-0002'], array_column($result->cues, 'cueId'));
        $this->assertSame('hello', $result->cues[0]['translatedText']);
        $this->assertSame('world', $result->cues[1]['translatedText']);
    }

    public function test_translation_degrades_cue_absent_from_model_output(): void
    {
        // The model returned an unrelated cueId (dropped/reordered the real cue), so
        // cue-0001 has no match and must degrade to its source text rather than
        // failing the whole job.
        CueTranslationAgent::fake([
            [
                'cues' => [
                    [
                        'cueId' => 'different-cue',
                        'index' => 0,
                        'translatedText' => 'bonjour',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->translateBatch($this->tokenizedSourceCues(), 'spa', 'fra');

        $this->assertSame('hola a todos', $result->cues[0]['translatedText']);
    }

    public function test_translation_degrades_empty_translated_text_to_source_text(): void
    {
        CueTranslationAgent::fake([
            [
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'translatedText' => '   ',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->translateBatch($this->tokenizedSourceCues(), 'spa', 'fra');

        $this->assertSame('hola a todos', $result->cues[0]['translatedText']);
    }

    public function test_translation_tolerates_cjk_source_without_echoed_source_text(): void
    {
        $sourceText = '我喜欢学习中文';

        CueTranslationAgent::fake([
            [
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'translatedText' => 'I like studying Chinese',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->sourceCue('cue-0001', 0, $sourceText),
            'tokens' => [['index' => 0, 'text' => $sourceText, 'normalizedText' => $sourceText]],
        ];

        $result = $this->translateBatch([$sourceCue], 'cmn', 'eng');

        $this->assertSame('I like studying Chinese', $result->cues[0]['translatedText']);
        $this->assertSame($sourceText, $result->cues[0]['sourceText']);
    }

    public function test_translation_requires_configured_model(): void
    {
        config(['ai.providers.openai.models.translation.default' => null]);

        CueTranslationAgent::fake([
            [
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'translatedText' => 'bonjour',
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        try {
            $this->translateBatch($this->tokenizedSourceCues(), 'spa', 'fra');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('Subtitle AI model is not configured.', $exception->getMessage());
            $this->assertSame('translation.default', $exception->context['model_key'] ?? null);
            CueTranslationAgent::assertNeverPrompted();

            return;
        }

        $this->fail('Expected missing translation model configuration to fail.');
    }

    public function test_full_enrichment_rejects_changed_translated_text(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'hola a todos',
                        'translatedText' => 'changed translation',
                        'tokens' => [
                            ['index' => 0, 'text' => 'hola'],
                            ['index' => 1, 'text' => 'a todos'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $this->assertProviderFailureReason(
            fn () => $this->enrichBatch($this->tokenizedSourceCues(), 'spa', 'fra'),
            'translation_identity_mismatch',
        );
    }

    public function test_full_enrichment_rejects_missing_source_translated_text(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'sourceText' => 'hola a todos',
                        'translatedText' => 'hola a todos',
                        'tokens' => [
                            ['index' => 0, 'text' => 'hola'],
                            ['index' => 1, 'text' => 'a todos'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $cues = $this->tokenizedSourceCues();
        unset($cues[0]['translatedText']);

        $this->assertProviderFailureReason(
            fn () => $this->enrichBatch($cues, 'spa', 'fra'),
            'missing_source_translation',
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
                        'romanization' => 'watashi wa nihongo o benkyo shite imasu',
                        'tokens' => [
                            ['index' => 0, 'romanization' => 'watashi'],
                            ['index' => 1, 'romanization' => 'wa'],
                            ['index' => 2, 'romanization' => 'nihongo'],
                            ['index' => 3, 'romanization' => 'o'],
                            ['index' => 4, 'romanization' => 'benkyo shite imasu'],
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

        $result = $this->romanizeBatch([$sourceCue], 'jpn');

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
                        'romanization' => 'kakiitemita',
                        'tokens' => [],
                    ],
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'romanization' => 'nihongo',
                        'tokens' => [
                            ['index' => 0, 'romanization' => 'nihongo'],
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
            fn () => $this->romanizeBatch([$tokenlessCue, $validCue], 'jpn'),
            'missing_source_tokens',
        );
    }

    public function test_romanization_attaches_romanization_by_index_preserving_source_tokens(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'romanization' => 'nihongo benkyo',
                        'tokens' => [
                            ['index' => 0, 'romanization' => 'nihongo'],
                            ['index' => 1, 'romanization' => 'benkyo'],
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

        $result = $this->romanizeBatch([$sourceCue], 'jpn');

        $this->assertSame(['日本語', '勉強'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame('nihongo', $result->cues[0]['tokens'][0]['romanization']);
        $this->assertSame('benkyo', $result->cues[0]['tokens'][1]['romanization']);
        $this->assertSame('nihongo benkyo', $result->cues[0]['romanization']);
    }

    public function test_romanization_attaches_pinyin_to_cjk_tokens_by_index(): void
    {
        $sourceText = '我喜欢学习中文';

        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'romanization' => 'wo xihuan xuexi zhongwen',
                        'tokens' => [
                            ['index' => 0, 'romanization' => 'wo'],
                            ['index' => 1, 'romanization' => 'xihuan'],
                            ['index' => 2, 'romanization' => 'xuexi'],
                            ['index' => 3, 'romanization' => 'zhongwen'],
                        ],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCue = [
            ...$this->sourceCue('cue-0001', 0, $sourceText),
            'translatedText' => $sourceText,
            'tokens' => [
                ['index' => 0, 'text' => '我', 'normalizedText' => '我'],
                ['index' => 1, 'text' => '喜欢', 'normalizedText' => '喜欢'],
                ['index' => 2, 'text' => '学习', 'normalizedText' => '学习'],
                ['index' => 3, 'text' => '中文', 'normalizedText' => '中文'],
            ],
        ];

        $result = $this->romanizeBatch([$sourceCue], 'cmn');

        $this->assertSame(['我', '喜欢', '学习', '中文'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame('xihuan', $result->cues[0]['tokens'][1]['romanization']);
        $this->assertSame('zhongwen', $result->cues[0]['tokens'][3]['romanization']);
    }

    public function test_romanization_degrades_when_token_count_differs(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'romanization' => 'nihongo benkyo',
                        'tokens' => [
                            ['index' => 0, 'romanization' => 'nihongo'],
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

        $result = $this->romanizeBatch([$sourceCue], 'jpn');

        $this->assertSame(['日本語', '勉強'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame('nihongo', $result->cues[0]['tokens'][0]['romanization']);
        $this->assertArrayNotHasKey('romanization', $result->cues[0]['tokens'][1]);
    }

    public function test_romanization_degrades_when_token_romanization_is_unparseable(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'romanization' => '   ',
                        'tokens' => [
                            ['index' => 0, 'romanization' => null],
                            ['index' => 1, 'romanization' => '  '],
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

        $result = $this->romanizeBatch([$sourceCue], 'jpn');

        $this->assertSame(['日本語', '勉強'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertArrayNotHasKey('romanization', $result->cues[0]['tokens'][0]);
        $this->assertArrayNotHasKey('romanization', $result->cues[0]['tokens'][1]);
        $this->assertArrayNotHasKey('romanization', $result->cues[0]);
    }

    public function test_romanization_matches_cues_by_id_when_model_reorders_output(): void
    {
        // Reproduces the production cue_identity_mismatch failure: the model returns
        // the batch's cues in a different order than the input. Matching by cueId
        // (not array position) keeps each cue's romanization correct.
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'romanization' => 'benkyo',
                        'tokens' => [['index' => 0, 'romanization' => 'benkyo']],
                    ],
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'romanization' => 'nihongo',
                        'tokens' => [['index' => 0, 'romanization' => 'nihongo']],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCues = [
            [
                ...$this->sourceCue('cue-0001', 0, '日本語'),
                'translatedText' => '日本語',
                'tokens' => [['index' => 0, 'text' => '日本語', 'normalizedText' => '日本語']],
            ],
            [
                ...$this->sourceCue('cue-0002', 1, '勉強'),
                'translatedText' => '勉強',
                'tokens' => [['index' => 0, 'text' => '勉強', 'normalizedText' => '勉強']],
            ],
        ];

        $result = $this->romanizeBatch($sourceCues, 'jpn');

        $this->assertSame(['cue-0001', 'cue-0002'], array_column($result->cues, 'cueId'));
        $this->assertSame('nihongo', $result->cues[0]['tokens'][0]['romanization']);
        $this->assertSame('benkyo', $result->cues[1]['tokens'][0]['romanization']);
    }

    public function test_romanization_degrades_cue_absent_from_model_output(): void
    {
        // The model dropped cue-0002 entirely. It must degrade to its source tokens
        // with no romanization rather than failing the whole job.
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'romanization' => 'nihongo',
                        'tokens' => [['index' => 0, 'romanization' => 'nihongo']],
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $sourceCues = [
            [
                ...$this->sourceCue('cue-0001', 0, '日本語'),
                'translatedText' => '日本語',
                'tokens' => [['index' => 0, 'text' => '日本語', 'normalizedText' => '日本語']],
            ],
            [
                ...$this->sourceCue('cue-0002', 1, '勉強'),
                'translatedText' => '勉強',
                'tokens' => [['index' => 0, 'text' => '勉強', 'normalizedText' => '勉強']],
            ],
        ];

        $result = $this->romanizeBatch($sourceCues, 'jpn');

        $this->assertSame('nihongo', $result->cues[0]['tokens'][0]['romanization']);
        $this->assertSame(['勉強'], array_column($result->cues[1]['tokens'], 'text'));
        $this->assertArrayNotHasKey('romanization', $result->cues[1]['tokens'][0]);
        $this->assertArrayNotHasKey('romanization', $result->cues[1]);
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
            $this->enrichBatch($this->tokenizedSourceCues(), 'eng', 'eng');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame(RequestException::class, $exception->context['exception'] ?? null);
            $this->assertSame(400, $exception->context['status'] ?? null);
            $this->assertSame(1, $calls);

            return;
        }

        $this->fail('Expected provider request failure to map to a subtitle processing exception.');
    }

    public function test_provider_http_429_maps_to_transient_rate_limited_error(): void
    {
        CueTokenizationAgent::fake(
            fn (): never => throw new RequestException(new Response(new PsrResponse(429))),
        )->preventStrayPrompts();

        try {
            $this->tokenizeBatch([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('rate_limited', $exception->publicCode);
            $this->assertSame(429, $exception->context['status'] ?? null);
            $this->assertTrue($exception->isTransient());

            return;
        }

        $this->fail('Expected provider 429 to map to a transient subtitle processing exception.');
    }

    public function test_provider_server_errors_map_to_transient_provider_unavailable_error(): void
    {
        CueTokenizationAgent::fake(
            fn (): never => throw new RequestException(new Response(new PsrResponse(503))),
        )->preventStrayPrompts();

        try {
            $this->tokenizeBatch([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_unavailable', $exception->publicCode);
            $this->assertSame(503, $exception->context['status'] ?? null);
            $this->assertTrue($exception->isTransient());

            return;
        }

        $this->fail('Expected provider 503 to map to a transient subtitle processing exception.');
    }

    public function test_provider_connection_failures_map_to_transient_provider_unavailable_error(): void
    {
        CueTokenizationAgent::fake(
            fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'),
        )->preventStrayPrompts();

        try {
            $this->tokenizeBatch([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_unavailable', $exception->publicCode);
            $this->assertSame('connection_failure', $exception->context['reason'] ?? null);
            $this->assertTrue($exception->isTransient());

            return;
        }

        $this->fail('Expected provider connection failure to map to a transient subtitle processing exception.');
    }

    public function test_validation_failures_are_not_transient(): void
    {
        $exception = SubtitleProcessingException::enrichmentFailed('Invalid output.', ['reason' => 'missing_cues']);

        $this->assertFalse($exception->isTransient());
    }

    public function test_provider_rate_limits_map_to_stable_rate_limited_error(): void
    {
        CueTokenizationAgent::fake(
            fn (): never => throw RateLimitedException::forProvider('openai', 429),
        )->preventStrayPrompts();

        try {
            $this->tokenizeBatch([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
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
            $this->tokenizeBatch([$this->sourceCue('cue-0001', 0, 'hola a todos')], 'spa');
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
     * @param  array<int, array<string, mixed>>  $cues
     */
    private function tokenizeBatch(array $cues, string $sourceLanguage): CueEnrichmentResult
    {
        return $this->provider()->tokenizeCueBatch($cues, $cues, $sourceLanguage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    private function enrichBatch(
        array $cues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization = true,
    ): CueEnrichmentResult {
        return $this->provider()->enrichCueBatch($cues, $sourceLanguage, $targetLanguage, $includeRomanization);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    private function translateBatch(array $cues, string $sourceLanguage, string $targetLanguage): CueEnrichmentResult
    {
        return $this->provider()->translateCueBatch($cues, $sourceLanguage, $targetLanguage, $cues);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    private function romanizeBatch(array $cues, string $sourceLanguage): CueEnrichmentResult
    {
        return $this->provider()->romanizeCueBatch($cues, $sourceLanguage);
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
