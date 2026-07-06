<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;

/**
 * Merges per-chunk Scribe payloads into one payload the normalizer can
 * consume. Word timestamps are offset by each chunk's audio start; the
 * symmetric chunk overlap means boundary words are heard whole by both
 * neighbouring chunks, so each timed word is kept from exactly one chunk --
 * the one whose nominal window contains the word's midpoint.
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

        $mergedWords = [];

        foreach (array_values($chunks) as $chunkIndex => $chunk) {
            array_push($mergedWords, ...$this->keptChunkWords($chunk, $chunkIndex));
        }

        $payload = ['words' => $mergedWords];
        $firstChunkLanguage = $chunks[array_key_first($chunks)]['payload']['language_code'] ?? null;

        // Language detection comes from one canonical chunk (the first);
        // per-chunk detection can disagree.
        if (is_string($firstChunkLanguage)) {
            $payload['language_code'] = $firstChunkLanguage;
        }

        return $payload;
    }

    /**
     * @param  array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}  $chunk
     * @return array<int, array<string, mixed>>
     */
    private function keptChunkWords(array $chunk, int $chunkIndex): array
    {
        $words = $chunk['payload']['words'] ?? null;

        if (! is_array($words)) {
            $this->failInvalidChunk('missing_words', $chunkIndex);
        }

        $kept = [];
        $pendingUntimed = [];
        $lastTimedWordKept = false;

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

            $lastTimedWordKept = $midpoint >= $chunk['nominalStartSeconds']
                && ($chunk['nominalEndSeconds'] === null || $midpoint < $chunk['nominalEndSeconds']);

            if ($lastTimedWordKept) {
                array_push($kept, ...$pendingUntimed);
                $kept[] = [
                    ...$token,
                    'start' => $start,
                    'end' => $end,
                ];
            }

            $pendingUntimed = [];
        }

        if ($pendingUntimed !== [] && $lastTimedWordKept) {
            array_push($kept, ...$pendingUntimed);
        }

        return $kept;
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
