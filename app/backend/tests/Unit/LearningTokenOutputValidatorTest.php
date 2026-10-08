<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningTokenOutputValidatorTest extends TestCase
{
    public function test_preserves_korean_spaces_inside_learning_tokens(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens([
            ['index' => 0, 'text' => '나는 학교에'],
            ['index' => 1, 'text' => '갑니다'],
        ], 0);
        $this->assertSame(['나는 학교에', '갑니다'], array_column($tokens, 'text'));
    }

    public function test_accepts_ordered_generated_tokens_without_source_spans(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens(
            [
                ['index' => 0, 'text' => 'Hola'],
                ['index' => 1, 'text' => 'a todos'],
            ],
            0,
        );

        $this->assertSame(['Hola', 'a todos'], array_column($tokens, 'text'));
        $this->assertSame(['hola', 'a todos'], array_column($tokens, 'normalizedText'));
    }

    public function test_accepts_grouped_no_space_tokens_when_provider_inserted_artifact_spaces(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens(
            [
                ['index' => 0, 'text' => "\u{65E5}\u{672C}\u{8A9E}"],
                ['index' => 1, 'text' => "\u{52C9}\u{5F37}"],
            ],
            0,
        );

        $this->assertSame(["\u{65E5}\u{672C}\u{8A9E}", "\u{52C9}\u{5F37}"], array_column($tokens, 'text'));
    }

    public function test_drops_punctuation_only_generated_tokens_and_reindexes(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens(
            [
                ['index' => 0, 'text' => 'hello'],
                ['index' => 1, 'text' => ','],
                ['index' => 2, 'text' => 'world'],
            ],
            0,
        );

        $this->assertSame([0, 1], array_column($tokens, 'index'));
        $this->assertSame(['hello', 'world'], array_column($tokens, 'text'));
    }

    public function test_accepts_short_space_delimited_phrases(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens(
            [
                ['index' => 0, 'text' => 'I'],
                ['index' => 1, 'text' => 'live'],
                ['index' => 2, 'text' => 'in'],
                ['index' => 3, 'text' => 'New York'],
                ['index' => 4, 'text' => 'now'],
            ],
            0,
        );

        $this->assertSame(['I', 'live', 'in', 'New York', 'now'], array_column($tokens, 'text'));
    }

    public function test_preserves_model_wording_and_order(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens(
            [
                ['index' => 0, 'text' => 'todos'],
                ['index' => 1, 'text' => 'Hola'],
            ],
            0,
        );

        $this->assertSame(['todos', 'Hola'], array_column($tokens, 'text'));
    }

    public function test_rejects_non_sequential_generated_token_indexes(): void
    {
        $this->assertRejectedTokenizationReason(
            [
                ['index' => 1, 'text' => 'Hola'],
            ],
            'invalid_token_index',
        );
    }

    public function test_rejects_empty_generated_token_output(): void
    {
        $this->assertRejectedTokenizationReason([], 'empty_tokens');
    }

    public function test_rejects_empty_generated_token_text(): void
    {
        $this->assertRejectedTokenizationReason(
            [
                ['index' => 0, 'text' => ' '],
            ],
            'invalid_token_text',
        );
    }

    #[DataProvider('unusableTokens')]
    public function test_rejects_malformed_or_non_lexical_output(mixed $tokens, string $reason): void
    {
        $this->assertRejectedTokenizationReason($tokens, $reason);
    }

    public static function unusableTokens(): array
    {
        return [
            'missing array' => [null, 'invalid_tokens'],
            'string array' => ['hello', 'invalid_tokens'],
            'scalar token' => [['hello'], 'invalid_token'],
            'missing text' => [[['index' => 0]], 'invalid_token_text'],
            'non-string text' => [[['index' => 0, 'text' => 123]], 'invalid_token_text'],
            'punctuation only' => [[['index' => 0, 'text' => '...?!']], 'empty_tokens'],
            'symbols only' => [[['index' => 0, 'text' => '🎵']], 'empty_tokens'],
            'combining marks only' => [[['index' => 0, 'text' => "\u{064E}\u{0651}"]], 'empty_tokens'],
        ];
    }

    #[DataProvider('sourceMatches')]
    public function test_tokens_must_spell_the_source_words_in_order(string $sourceText, array $tokenTexts, bool $matches): void
    {
        $tokens = array_map(fn (string $text): array => ['text' => $text], $tokenTexts);
        $this->assertSame($matches, $this->validator()->tokensMatchSourceText($tokens, $sourceText));
    }

    public static function sourceMatches(): array
    {
        return [
            'punctuation and case' => ['Hello, World!', ['hello', 'world'], true],
            'curly apostrophe' => ['Don’t stop', ["Don't", 'stop'], true],
            'scribe spacing in japanese' => ['日本 語を 勉強', ['日本語', 'を', '勉強'], true],
            'rewritten word' => ['their there', ['there', 'there'], false],
            'missing word' => ['one two three', ['one', 'three'], false],
            'reordered words' => ['one two', ['two', 'one'], false],
            'corrected script' => ['يديנו', ['يدينو'], false],
        ];
    }

    private function assertRejectedTokenizationReason(mixed $tokens, string $reason): void
    {
        try {
            $this->validator()->validatedGeneratedTokens($tokens, 0);
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame($reason, $exception->context['reason'] ?? null);

            return;
        }

        $this->fail("Expected token output to fail with {$reason}.");
    }

    private function validator(): LearningTokenOutputValidator
    {
        return new LearningTokenOutputValidator;
    }
}
