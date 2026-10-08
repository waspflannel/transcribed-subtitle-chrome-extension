<?php

namespace App\Services\TranslationAnalysis;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Text\SubtitleText;

class LearningTokenOutputValidator
{
    /**
     * @return array<int, array{index: int, text: string, normalizedText: string}>
     */
    public function validatedGeneratedTokens(mixed $tokens, int $cueIndex): array
    {
        if (! is_array($tokens)) {
            $this->failInvalidOutput('invalid_tokens', ['cue_index' => $cueIndex]);
        }

        if ($tokens === []) {
            $this->failInvalidOutput('empty_tokens', ['cue_index' => $cueIndex]);
        }

        $validated = [];

        foreach (array_values($tokens) as $position => $token) {
            if (! is_array($token)) {
                $this->failInvalidOutput('invalid_token', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            if (($token['index'] ?? null) !== $position) {
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

            if (! $this->isLexicalTokenText($text)) {
                continue;
            }

            $validated[] = [
                'index' => count($validated),
                'text' => $text,
                'normalizedText' => $this->normalizeTokenText($text),
            ];
        }

        if ($validated === []) {
            $this->failInvalidOutput('empty_tokens', ['cue_index' => $cueIndex]);
        }

        return $validated;
    }

    public function normalizeTokenText(string $text): string
    {
        return mb_strtolower(SubtitleText::canonicalComparable($text), 'UTF-8');
    }

    /**
     * True when the tokens spell the cue's words in order, ignoring case, spacing and punctuation.
     *
     * @param  array<int, array{text: string}>  $tokens
     */
    public function tokensMatchSourceText(array $tokens, string $sourceText): bool
    {
        $words = fn (string $text): string => (string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', $this->normalizeTokenText($text));

        return $words(implode(' ', array_column($tokens, 'text'))) === $words($sourceText);
    }

    private function isLexicalTokenText(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = SubtitleText::canonicalComparable($value);

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
