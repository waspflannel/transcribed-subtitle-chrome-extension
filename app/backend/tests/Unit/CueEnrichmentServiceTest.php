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
            fn ($prompt): bool => $prompt->contains('"sourceLanguage":"jpn"')
                && $prompt->contains('Return tokens only')
                && $prompt->contains($sourceText),
        );
    }

    public function test_tokenization_validation_failure_retries_failed_cue_with_rejection_reason(): void
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
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($sourceText, ['Hola', 'a todos']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame(['Hola', 'a todos'], array_column($result->cues[0]['tokens'], 'text'));

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.retry')
                && $prompt->contains('token_text_not_in_source')
                && $prompt->contains('This is a retry'),
        );
    }

    public function test_tokenization_retries_only_failed_cues(): void
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
                        'cueId' => 'cue-0002',
                        'index' => 1,
                        'tokens' => $this->generatedTokens($failedSourceText, ['good', 'morning']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $validSourceText),
            $this->sourceCue('cue-0002', 1, $failedSourceText),
        ], 'jpn');

        $this->assertSame(['Hola', 'amiga'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame(['good', 'morning'], array_column($result->cues[1]['tokens'], 'text'));

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.default')
                && $prompt->contains('"nextCueText":"good morning"')
                && $prompt->contains('"previousCueText":"Hola amiga"'),
        );

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.retry')
                && $prompt->contains('"cueId":"cue-0002"')
                && ! $prompt->contains('"cueId":"cue-0001"'),
        );
    }

    public function test_tokenization_retry_failure_stores_transcript_only_cue(): void
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
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($sourceText, ['still missing']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame($sourceText, $result->cues[0]['translatedText']);
        $this->assertSame([], $result->cues[0]['tokens']);
    }

    public function test_tokenization_retry_provider_failure_preserves_valid_first_pass_cues(): void
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
            fn (): never => throw new RuntimeException('retry provider unavailable'),
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $validSourceText),
            $this->sourceCue('cue-0002', 1, $failedSourceText),
        ], 'jpn');

        $this->assertSame(['Hola', 'amiga'], array_column($result->cues[0]['tokens'], 'text'));
        $this->assertSame([], $result->cues[1]['tokens']);
    }

    public function test_tokenization_accepts_valid_first_pass_without_retry(): void
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

        CueTokenizationAgent::assertNotPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.retry'),
        );
    }

    public function test_tokenization_prompt_uses_normalized_source_text_without_span_inputs(): void
    {
        $sourceText = 'っ と よ ）。 伝 わ な い や な ん て タ ゲ ッ';

        CueTokenizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
                    [
                        'cueId' => 'cue-0001',
                        'index' => 0,
                        'tokens' => $this->generatedTokens($sourceText, ['っとよ', '伝わない', 'や', 'なんて', 'タゲッ']),
                    ],
                ],
            ],
        ])->preventStrayPrompts();

        $result = $this->provider()->tokenize([
            $this->sourceCue('cue-0001', 0, $sourceText),
        ], 'jpn');

        $this->assertSame($sourceText, $result->cues[0]['sourceText']);
        $this->assertSame(['っとよ', '伝わない', 'や', 'なんて', 'タゲッ'], array_column($result->cues[0]['tokens'], 'text'));

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"sourceText":"っ と よ ）。 伝 わ な い や な ん て タ ゲ ッ"')
                && $prompt->contains('transcription artifacts')
                && ! $prompt->contains('tokenizationText')
                && ! $prompt->contains('sourceStart')
                && ! $prompt->contains('sourceEnd'),
        );
    }

    public function test_tokenization_changed_cue_identity_falls_back_to_transcript_only_after_retry(): void
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

        $result = $this->provider()->tokenize([$this->sourceCue('cue-0001', 0, $sourceText)], 'jpn');

        $this->assertSame([], $result->cues[0]['tokens']);

        CueTokenizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->model === (string) config('ai.providers.openai.models.tokenization.retry')
                && $prompt->contains('cue_identity_mismatch'),
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
            fn ($prompt): bool => $prompt->contains('"targetLanguage":"fra"')
                && $prompt->contains('"includeRomanization":true')
                && $prompt->contains('token count')
                && $prompt->contains('"text":"a todos"'),
        );
    }

    public function test_full_enrichment_skips_transcript_only_cues(): void
    {
        CueEnrichmentAgent::fake([
            [
                'dialect' => 'castilian',
                'cues' => [
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

        $result = $this->provider()->enrich([$tokenlessCue, $validCue], 'spa', 'eng');

        $this->assertSame([], $result->cues[0]['tokens']);
        $this->assertSame('hello', $result->cues[1]['translatedText']);
        $this->assertSame('hello', $result->cues[1]['tokens'][0]['gloss']);

        CueEnrichmentAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"cueId":"cue-0002"')
                && ! $prompt->contains('"cueId":"cue-0001"'),
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
            fn ($prompt): bool => $prompt->contains('Do not translate, retokenize')
                && $prompt->contains('Hepburn for Japanese')
                && $prompt->contains('"text":"日本語"'),
        );
    }

    public function test_romanization_skips_transcript_only_cues_and_preserves_valid_boundaries(): void
    {
        CueRomanizationAgent::fake([
            [
                'dialect' => 'unknown',
                'cues' => [
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
            ...$this->sourceCue('cue-0001', 0, 'か聞いてみた'),
            'translatedText' => 'か聞いてみた',
            'tokens' => [],
        ];
        $validCue = [
            ...$this->sourceCue('cue-0002', 1, '日本語'),
            'translatedText' => '日本語',
            'tokens' => [
                ['index' => 0, 'text' => '日本語', 'normalizedText' => '日本語'],
            ],
        ];

        $result = $this->provider()->romanize([$tokenlessCue, $validCue], 'jpn');

        $this->assertSame([], $result->cues[0]['tokens']);
        $this->assertArrayNotHasKey('romanization', $result->cues[0]);
        $this->assertSame('nihongo', $result->cues[1]['romanization']);
        $this->assertSame(['日本語'], array_column($result->cues[1]['tokens'], 'text'));

        CueRomanizationAgent::assertPrompted(
            fn ($prompt): bool => $prompt->contains('"cueId":"cue-0002"')
                && ! $prompt->contains('"cueId":"cue-0001"'),
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
    }

    public function test_provider_request_errors_map_without_split_retry(): void
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

        $this->fail('Expected provider request failure to map without split retries.');
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
            $this->assertSame(
                (string) config('ai.providers.openai.models.tokenization.default'),
                $exception->context['model'] ?? null,
            );

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
