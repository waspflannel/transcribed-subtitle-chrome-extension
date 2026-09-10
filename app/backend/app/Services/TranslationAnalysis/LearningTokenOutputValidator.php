<?php

namespace App\Services\TranslationAnalysis;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Text\SubtitleText;

class LearningTokenOutputValidator
{
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
        $sourceComparable = SubtitleText::canonicalComparable($sourceText);
        $searchOffset = 0;
        preg_match_all('/\X/u', $sourceComparable, $graphemes, PREG_OFFSET_CAPTURE);
        $boundaries = array_fill_keys(array_column($graphemes[0], 1), true);
        $boundaries[strlen($sourceComparable)] = true;

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

            $tokenComparable = $text;

            if ($tokenComparable === '') {
                $this->failInvalidOutput('invalid_token_text', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            $sourcePosition = strpos($sourceComparable, $tokenComparable, $searchOffset);

            if ($sourcePosition === false) {
                $this->failInvalidOutput('token_text_not_in_source', [
                    'cue_index' => $cueIndex,
                    'token_position' => $position,
                ]);
            }

            if ($this->isLexicalTokenText(substr($sourceComparable, $searchOffset, $sourcePosition - $searchOffset))) {
                $this->failInvalidOutput('uncovered_source_text', ['cue_index' => $cueIndex, 'token_position' => $position]);
            }

            $end = $sourcePosition + strlen($tokenComparable);
            foreach ([$sourcePosition, $end] as $boundary) {
                if (! isset($boundaries[$boundary]) || $this->isInsideSpacedWord($sourceComparable, $boundary)) {
                    $this->failInvalidOutput('invalid_token_boundary', ['cue_index' => $cueIndex, 'token_position' => $position]);
                }
            }

            $searchOffset = $end;
            $validated[] = [
                'index' => count($validated),
                'text' => $text,
                'normalizedText' => $this->normalizeTokenText($text),
            ];
        }

        if ($validated === []) {
            $this->failInvalidOutput('empty_tokens', ['cue_index' => $cueIndex]);
        }

        if ($this->isLexicalTokenText(substr($sourceComparable, $searchOffset))) {
            $this->failInvalidOutput('uncovered_source_text', ['cue_index' => $cueIndex]);
        }

        return $validated;
    }

    public function normalizeTokenText(string $text): string
    {
        $normalized = SubtitleText::canonicalComparable($text);

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }

    private function isInsideSpacedWord(string $text, int $offset): bool
    {
        $left = mb_substr(substr($text, 0, $offset), -1, 1, 'UTF-8');
        $right = mb_substr(substr($text, $offset), 0, 1, 'UTF-8');

        if ($left === '' || $right === '' || preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u', $left.$right) === 1) {
            return false;
        }

        $internalPunctuation = '/(?:[\p{L}\p{N}][\x{0027}\x{2019}\p{Pd}][\p{L}\p{N}]|\p{N}[.,:\/\x{066B}\x{066C}]\p{N})/u';

        return preg_match('/[\p{L}\p{N}\p{M}][\p{L}\p{N}\p{M}]/u', $left.$right) === 1
            || preg_match($internalPunctuation, mb_substr(substr($text, 0, $offset), -2, 2, 'UTF-8').$right) === 1
            || preg_match($internalPunctuation, $left.mb_substr(substr($text, $offset), 0, 2, 'UTF-8')) === 1;
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
