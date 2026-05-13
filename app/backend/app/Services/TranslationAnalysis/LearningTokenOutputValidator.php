<?php

namespace App\Services\TranslationAnalysis;

use App\Exceptions\SubtitleProcessingException;

class LearningTokenOutputValidator
{
    private const NO_SPACE_ARTIFACT_BOUNDARY_PATTERN = '/(?<=[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}\x{AC00}-\x{D7AF}\x{FF66}-\x{FF9D}\x{0E00}-\x{0E7F}\x{0E80}-\x{0EFF}\x{1780}-\x{17FF}\x{1000}-\x{109F}\p{P}\p{S}])\s+(?=[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}\x{AC00}-\x{D7AF}\x{FF66}-\x{FF9D}\x{0E00}-\x{0E7F}\x{0E80}-\x{0EFF}\x{1780}-\x{17FF}\x{1000}-\x{109F}\p{P}\p{S}])/u';

    /**
     * @return array<int, array{index: int, text: string, normalizedText: string}>
     */
    public function validatedGeneratedTokens(mixed $tokens, string $sourceText, int $cueIndex): array
    {
        if (! is_array($tokens)) {
            $this->failInvalidOutput('invalid_tokens', ['cue_index' => $cueIndex]);
        }

        if ($tokens === []) {
            $this->failInvalidOutput('empty_tokens', ['cue_index' => $cueIndex]);
        }

        $validated = [];
        $sourceComparable = $this->comparableText($sourceText);
        $searchOffset = 0;

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

            $tokenComparable = $this->comparableText($text);

            if ($tokenComparable === '') {
                $this->failInvalidOutput('invalid_token_text', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $sourcePosition = mb_strpos($sourceComparable, $tokenComparable, $searchOffset, 'UTF-8');

            if ($sourcePosition === false) {
                $this->failInvalidOutput('token_text_not_in_source', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $searchOffset = $sourcePosition + mb_strlen($tokenComparable, 'UTF-8');
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
        $normalized = $this->normalizeTextForComparison($text);

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }

    private function comparableText(string $text): string
    {
        return $this->normalizeTokenText($text);
    }

    private function isLexicalTokenText(string $text): bool
    {
        return preg_match('/[\p{L}\p{N}\p{M}]/u', $text) === 1;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $cleaned = $this->normalizeTextForComparison($value);

        return $cleaned === '' ? null : $cleaned;
    }

    private function normalizeTextForComparison(string $text): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));

        return (string) preg_replace(self::NO_SPACE_ARTIFACT_BOUNDARY_PATTERN, '', $normalized);
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
