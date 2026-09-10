<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Languages\LanguageCatalog;

/**
 * Merges per-chunk Scribe payloads into one payload the normalizer can
 * consume. Word timestamps are offset by each chunk's audio start. Timed
 * words keep the existing nominal-midpoint ownership unless matching text
 * and strictly overlapping timing positively identify the same word in
 * adjacent overlap.
 *
 * Untimed word tokens carry no position of their own; they travel with the
 * next timed word in their chunk (matching how the normalizer glues them),
 * or with the chunk's last timed word when they trail the chunk.
 */
class ScribeChunkPayloadMerger
{
    /**
     * @param  array<int, array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}>  $chunks
     * @return array<string, mixed>
     */
    public function merge(array $chunks): array
    {
        if ($chunks === []) {
            $this->failInvalidChunk('empty_chunk_list', null);
        }

        $chunks = array_values($chunks);
        $chunkWords = [];
        $keep = [];
        $nextWordId = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkWords[$chunkIndex] = $this->chunkWords($chunk, $chunkIndex, $nextWordId);

            foreach ($chunkWords[$chunkIndex] as $word) {
                $keep[$word['id']] = $word['owned'];
            }
        }

        $boundaryMatches = [];

        foreach ($chunkWords as $chunkIndex => $words) {
            if (! isset($chunkWords[$chunkIndex + 1])) {
                break;
            }

            $boundaryMatches[$chunkIndex] = $this->reconcileAdjacentOverlap(
                $keep,
                $words,
                $chunkWords[$chunkIndex + 1],
                $chunks[$chunkIndex],
                $chunks[$chunkIndex + 1],
            );
        }

        $mergedWords = [];

        foreach ($chunkWords as $words) {
            foreach ($words as $word) {
                if ($keep[$word['id']] ?? false) {
                    $mergedWords[] = $word;
                }
            }
        }

        foreach ($boundaryMatches as $chunkIndex => $matches) {
            if ($matches === []) {
                continue;
            }

            $mergedWords = $this->applyBoundaryOrder(
                $mergedWords,
                $keep,
                $chunkWords[$chunkIndex],
                $chunkWords[$chunkIndex + 1],
                $matches,
            );
        }

        $payloadWords = [];

        foreach ($mergedWords as $word) {
            array_push($payloadWords, ...$word['untimedBefore']);
            $payloadWords[] = $word['token'];
            array_push($payloadWords, ...$word['untimedAfter']);
        }

        $payload = ['words' => $payloadWords];
        $languageWeights = [];
        foreach ($chunks as $index => $chunk) {
            $language = LanguageCatalog::normalizeCode($chunk['payload']['language_code'] ?? null);
            if ($language === null || $language === 'auto') {
                continue;
            }
            // Only speech owned by this chunk votes; overlap must not vote twice.
            $speechSeconds = 0.0;
            foreach ($chunkWords[$index] as $word) {
                if ($word['owned']) {
                    $speechSeconds += max(0, min($word['end'], $chunk['nominalEndSeconds'] ?? $word['end'])
                        - max($word['start'], $chunk['nominalStartSeconds']));
                }
            }
            $confidence = $chunk['payload']['language_probability'] ?? 1.0;
            $languageWeights[$language] = ($languageWeights[$language] ?? 0.0) + $speechSeconds * $confidence;
        }
        if ($languageWeights !== []) {
            $languageWeights = array_map(fn (float $weight): float => round($weight, 6), $languageWeights);
            arsort($languageWeights, SORT_NUMERIC);
            $payload['language_code'] = array_key_first($languageWeights);
        }

        return $payload;
    }

    /**
     * @param  array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}  $chunk
     * @return array<int, array<string, mixed>>
     */
    private function chunkWords(array $chunk, int $chunkIndex, int &$nextWordId): array
    {
        $words = $chunk['payload']['words'] ?? null;

        if (! is_array($words)) {
            $this->failInvalidChunk('missing_words', $chunkIndex);
        }

        $parsed = [];
        $pendingUntimed = [];
        foreach ($words as $token) {
            if (! is_array($token)) {
                $this->failInvalidChunk('invalid_token', $chunkIndex);
            }

            if (($token['type'] ?? null) !== 'word') {
                continue;
            }

            if (! $this->hasUsableTiming($token)) {
                $pendingUntimed[] = $token;

                continue;
            }

            $start = (float) $token['start'] + $chunk['audioStartSeconds'];
            $end = (float) $token['end'] + $chunk['audioStartSeconds'];
            $midpoint = ($start + $end) / 2;
            $owned = $midpoint >= $chunk['nominalStartSeconds']
                && ($chunk['nominalEndSeconds'] === null || $midpoint < $chunk['nominalEndSeconds']);

            $parsed[] = [
                'id' => $nextWordId++,
                'token' => [
                    ...$token,
                    'start' => $start,
                    'end' => $end,
                ],
                'start' => $start,
                'end' => $end,
                'owned' => $owned,
                'untimedBefore' => $pendingUntimed,
                'untimedAfter' => [],
            ];

            $pendingUntimed = [];
        }

        if ($pendingUntimed !== [] && $parsed !== []) {
            $parsed[array_key_last($parsed)]['untimedAfter'] = $pendingUntimed;
        }

        return $parsed;
    }

    /**
     * @param  array<int, bool>  $keep
     * @param  array<int, array<string, mixed>>  $leftWords
     * @param  array<int, array<string, mixed>>  $rightWords
     * @param  array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}  $leftChunk
     * @param  array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}  $rightChunk
     */
    private function reconcileAdjacentOverlap(
        array &$keep,
        array $leftWords,
        array $rightWords,
        array $leftChunk,
        array $rightChunk,
    ): array {
        if (! is_numeric($leftChunk['nominalEndSeconds']) || ! is_numeric($rightChunk['nominalStartSeconds'])) {
            return [];
        }

        $boundary = (float) $rightChunk['nominalStartSeconds'];
        $audioStart = (float) $rightChunk['audioStartSeconds'];
        $overlapSeconds = max(0.0, $boundary - $audioStart);
        $overlapStart = min($boundary, $audioStart);
        $overlapEnd = $boundary + $overlapSeconds;
        $leftCandidates = $this->boundaryCandidates($leftWords, $overlapStart, $overlapEnd);
        $rightCandidates = $this->boundaryCandidates($rightWords, $overlapStart, $overlapEnd);

        $matches = $this->matchingBoundaryWords($leftCandidates, $rightCandidates);

        foreach ($matches as [$leftWord, $rightWord]) {
            $winner = $this->boundaryWinner($keep, $leftWord, $rightWord);
            $loser = $winner === $leftWord['id'] ? $rightWord['id'] : $leftWord['id'];
            $keep[$winner] = true;
            $keep[$loser] = false;
        }

        return $matches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mergedWords
     * @param  array<int, bool>  $keep
     * @param  array<int, array<string, mixed>>  $leftWords
     * @param  array<int, array<string, mixed>>  $rightWords
     * @param  array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function applyBoundaryOrder(
        array $mergedWords,
        array $keep,
        array $leftWords,
        array $rightWords,
        array $matches,
    ): array {
        $localWords = $this->mergeBoundarySequences($keep, $leftWords, $rightWords, $matches);
        $targetIds = [];

        foreach ([...$leftWords, ...$rightWords] as $word) {
            if ($keep[$word['id']] ?? false) {
                $targetIds[$word['id']] = true;
            }
        }

        $orderedWords = [];
        $localIndex = 0;

        foreach ($mergedWords as $word) {
            if ($targetIds[$word['id']] ?? false) {
                $orderedWords[] = $localWords[$localIndex++];

                continue;
            }

            $orderedWords[] = $word;
        }

        return $orderedWords;
    }

    /**
     * @param  array<int, bool>  $keep
     * @param  array<int, array<string, mixed>>  $leftWords
     * @param  array<int, array<string, mixed>>  $rightWords
     * @param  array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function mergeBoundarySequences(
        array $keep,
        array $leftWords,
        array $rightWords,
        array $matches,
    ): array {
        $merged = [];
        $leftCursor = 0;
        $rightCursor = 0;

        foreach ($matches as [$leftMatch, $rightMatch]) {
            $leftIndex = $this->wordIndex($leftWords, $leftMatch['id']);
            $rightIndex = $this->wordIndex($rightWords, $rightMatch['id']);

            $this->appendKeptWords($merged, $leftWords, $leftCursor, $leftIndex, $keep);
            $this->appendKeptWords($merged, $rightWords, $rightCursor, $rightIndex, $keep);

            $winnerId = $this->boundaryWinner($keep, $leftMatch, $rightMatch);
            $merged[] = $winnerId === $leftMatch['id'] ? $leftMatch : $rightMatch;
            $leftCursor = $leftIndex + 1;
            $rightCursor = $rightIndex + 1;
        }

        $this->appendKeptWords($merged, $leftWords, $leftCursor, count($leftWords), $keep);
        $this->appendKeptWords($merged, $rightWords, $rightCursor, count($rightWords), $keep);

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $merged
     * @param  array<int, array<string, mixed>>  $words
     * @param  array<int, bool>  $keep
     */
    private function appendKeptWords(
        array &$merged,
        array $words,
        int $from,
        int $to,
        array $keep,
    ): void {
        for ($index = $from; $index < $to; $index++) {
            $word = $words[$index];

            if ($keep[$word['id']] ?? false) {
                $merged[] = $word;
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $words
     */
    private function wordIndex(array $words, int $wordId): int
    {
        foreach ($words as $index => $word) {
            if ($word['id'] === $wordId) {
                return $index;
            }
        }

        return count($words);
    }

    /**
     * @param  array<int, array<string, mixed>>  $words
     * @return array<int, array<string, mixed>>
     */
    private function boundaryCandidates(array $words, float $overlapStart, float $overlapEnd): array
    {
        return array_values(array_filter(
            $words,
            static fn (array $word): bool => $word['end'] >= $overlapStart && $word['start'] <= $overlapEnd,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $leftWords
     * @param  array<int, array<string, mixed>>  $rightWords
     * @return array<int, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    private function matchingBoundaryWords(array $leftWords, array $rightWords): array
    {
        $leftMatches = [];
        $rightMatches = [];

        foreach ($leftWords as $leftIndex => $leftWord) {
            $matchingRightIndex = null;

            foreach ($rightWords as $rightIndex => $rightWord) {
                if (! $this->sameBoundaryText($leftWord, $rightWord)
                    || ! $this->boundaryTimingAgrees($leftWord, $rightWord)) {
                    continue;
                }

                if ($matchingRightIndex !== null) {
                    $matchingRightIndex = null;
                    break;
                }

                $matchingRightIndex = $rightIndex;
            }

            $leftMatches[$leftIndex] = $matchingRightIndex;
        }

        foreach ($rightWords as $rightIndex => $rightWord) {
            $matchingLeftIndex = null;

            foreach ($leftWords as $leftIndex => $leftWord) {
                if (! $this->sameBoundaryText($leftWord, $rightWord)
                    || ! $this->boundaryTimingAgrees($leftWord, $rightWord)) {
                    continue;
                }

                if ($matchingLeftIndex !== null) {
                    $matchingLeftIndex = null;
                    break;
                }

                $matchingLeftIndex = $leftIndex;
            }

            $rightMatches[$rightIndex] = $matchingLeftIndex;
        }

        $paired = [];
        $lastRightIndex = -1;

        foreach ($leftMatches as $leftIndex => $rightIndex) {
            if ($rightIndex === null
                || $rightMatches[$rightIndex] !== $leftIndex
                || $rightIndex <= $lastRightIndex) {
                continue;
            }

            $paired[] = [$leftWords[$leftIndex], $rightWords[$rightIndex]];
            $lastRightIndex = $rightIndex;
        }

        return $paired;
    }

    /**
     * @param  array<string, mixed>  $leftWord
     * @param  array<string, mixed>  $rightWord
     */
    private function sameBoundaryText(array $leftWord, array $rightWord): bool
    {
        $leftText = $leftWord['token']['text'] ?? null;
        $rightText = $rightWord['token']['text'] ?? null;

        if (! is_string($leftText) || ! is_string($rightText)) {
            return false;
        }

        return ($this->comparableBoundaryText($leftText) !== '')
            && $this->comparableBoundaryText($leftText) === $this->comparableBoundaryText($rightText);
    }

    private function comparableBoundaryText(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $leftWord
     * @param  array<string, mixed>  $rightWord
     */
    private function boundaryTimingAgrees(array $leftWord, array $rightWord): bool
    {
        return max($leftWord['start'], $rightWord['start'])
            < min($leftWord['end'], $rightWord['end']);
    }

    /**
     * @param  array<int, bool>  $keep
     * @param  array<string, mixed>  $leftWord
     * @param  array<string, mixed>  $rightWord
     */
    private function boundaryWinner(array $keep, array $leftWord, array $rightWord): int
    {
        $leftKept = $keep[$leftWord['id']] ?? false;
        $rightKept = $keep[$rightWord['id']] ?? false;

        if ($leftKept !== $rightKept) {
            return $leftKept ? $leftWord['id'] : $rightWord['id'];
        }

        return $leftWord['id'];
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private function hasUsableTiming(array $token): bool
    {
        if (! is_numeric($token['start'] ?? null) || ! is_numeric($token['end'] ?? null)) {
            return false;
        }

        return (float) $token['start'] >= 0 && (float) $token['end'] > (float) $token['start'];
    }

    private function failInvalidChunk(string $reason, ?int $chunkIndex): never
    {
        throw SubtitleProcessingException::transcriptionFailed(
            'Transcription provider returned unusable chunked output.',
            [
                'reason' => $reason,
                ...($chunkIndex === null ? [] : ['chunk_index' => $chunkIndex]),
            ],
        );
    }
}
