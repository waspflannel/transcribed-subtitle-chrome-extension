<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Tests\TestCase;

class LearningTokenOutputValidatorTest extends TestCase
{
    public function test_accepts_ordered_generated_tokens_without_source_spans(): void
    {
        $tokens = $this->validator()->validatedGeneratedTokens(
            [
                ['index' => 0, 'text' => 'Hola'],
                ['index' => 1, 'text' => 'a todos'],
            ],
            'Hola a todos',
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
            "\u{65E5} \u{672C} \u{8A9E} \u{3092} \u{52C9} \u{5F37}",
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
            'hello, world',
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
            'I live in New York now',
            0,
        );

        $this->assertSame(['I', 'live', 'in', 'New York', 'now'], array_column($tokens, 'text'));
    }

    public function test_rejects_out_of_order_generated_tokens(): void
    {
        $this->assertRejectedTokenizationReason(
            [
                ['index' => 0, 'text' => 'todos'],
                ['index' => 1, 'text' => 'Hola'],
            ],
            'Hola a todos',
            'token_text_not_in_source',
        );
    }

    public function test_rejects_non_sequential_generated_token_indexes(): void
    {
        $this->assertRejectedTokenizationReason(
            [
                ['index' => 1, 'text' => 'Hola'],
            ],
            'Hola a todos',
            'invalid_token_index',
        );
    }

    public function test_rejects_empty_generated_token_output(): void
    {
        $this->assertRejectedTokenizationReason([], 'Hola a todos', 'empty_tokens');
    }

    public function test_rejects_empty_generated_token_text(): void
    {
        $this->assertRejectedTokenizationReason(
            [
                ['index' => 0, 'text' => ' '],
            ],
            'Hola a todos',
            'invalid_token_text',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $tokens
     */
    private function assertRejectedTokenizationReason(array $tokens, string $sourceText, string $reason): void
    {
        try {
            $this->validator()->validatedGeneratedTokens($tokens, $sourceText, 0);
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
