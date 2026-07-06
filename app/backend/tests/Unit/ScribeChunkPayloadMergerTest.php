<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Transcription\ScribeChunkPayloadMerger;
use Tests\TestCase;

class ScribeChunkPayloadMergerTest extends TestCase
{
    public function test_offsets_word_timestamps_by_chunk_audio_start(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('Hola', 0.5, 0.9)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('Adios', 2.5, 2.9)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(
            [['Hola', 0.5, 0.9], ['Adios', 100.5, 100.9]],
            array_map(fn (array $word): array => [$word['text'], $word['start'], $word['end']], $merged['words']),
        );
    }

    public function test_keeps_each_boundary_word_from_exactly_one_chunk(): void
    {
        // Both chunks hear the word straddling the 100s boundary: chunk 0 in
        // its trailing overlap, chunk 1 at its start. The midpoint (99.4s)
        // falls in chunk 0's nominal window, so only chunk 0's copy survives.
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('primera', 1.0, 1.4),
                    $this->word('frontera.', 99.0, 99.8),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('frontera.', 1.0, 1.8),
                    $this->word('segunda', 2.5, 2.9),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(
            ['primera', 'frontera.', 'segunda'],
            array_column($merged['words'], 'text'),
        );
    }

    public function test_word_with_midpoint_past_boundary_is_kept_from_the_later_chunk(): void
    {
        // Midpoint 100.4s belongs to chunk 1's window, so chunk 0's copy is
        // dropped even though chunk 0 heard the word in its trailing overlap.
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('tarde', 100.0, 100.8)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('tarde', 2.0, 2.8)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertCount(1, $merged['words']);
        $this->assertSame(100.0, $merged['words'][0]['start']);
        $this->assertSame(100.8, $merged['words'][0]['end']);
    }

    public function test_untimed_words_travel_with_their_following_timed_word(): void
    {
        $keptUntimed = ['text' => 'glued', 'type' => 'word'];
        $droppedUntimed = ['text' => 'dropped-glue', 'type' => 'word'];

        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $keptUntimed,
                    $this->word('dentro', 1.0, 1.4),
                    $droppedUntimed,
                    // Midpoint past the boundary: dropped with its glue.
                    $this->word('fuera', 100.2, 100.8),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
        ]);

        $this->assertSame(['glued', 'dentro'], array_column($merged['words'], 'text'));
    }

    public function test_trailing_untimed_words_follow_the_last_timed_word(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('final', 1.0, 1.4),
                    ['text' => 'trailing-glue', 'type' => 'word'],
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['final', 'trailing-glue'], array_column($merged['words'], 'text'));
    }

    public function test_language_code_comes_from_the_first_chunk_only(): void
    {
        // Per-chunk detection can disagree; the first chunk is canonical.
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('uno', 0.5, 0.9)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
                languageCode: 'es',
            ),
            $this->chunk(
                words: [$this->word('dos', 2.5, 2.9)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
                languageCode: 'en',
            ),
        ]);

        $this->assertSame('es', $merged['language_code']);
    }

    public function test_non_word_tokens_are_dropped(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('palabra', 0.5, 0.9),
                    ['text' => ' ', 'start' => 0.9, 'end' => 1.0, 'type' => 'spacing'],
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['palabra'], array_column($merged['words'], 'text'));
    }

    public function test_chunk_without_words_fails_with_chunk_index(): void
    {
        try {
            $this->merger()->merge([
                $this->chunk(
                    words: [$this->word('uno', 0.5, 0.9)],
                    audioStart: 0.0,
                    nominalStart: 0.0,
                    nominalEnd: 100.0,
                ),
                [
                    'payload' => ['language_code' => 'es'],
                    'audioStartSeconds' => 98.0,
                    'nominalStartSeconds' => 100.0,
                    'nominalEndSeconds' => null,
                ],
            ]);
            $this->fail('Expected a chunk without words to fail.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('missing_words', $exception->context['reason'] ?? null);
            $this->assertSame(1, $exception->context['chunk_index'] ?? null);
        }
    }

    private function merger(): ScribeChunkPayloadMerger
    {
        return new ScribeChunkPayloadMerger;
    }

    /**
     * @param  array<int, array<string, mixed>>  $words
     * @return array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}
     */
    private function chunk(
        array $words,
        float $audioStart,
        float $nominalStart,
        ?float $nominalEnd,
        string $languageCode = 'es',
    ): array {
        return [
            'payload' => [
                'language_code' => $languageCode,
                'words' => $words,
            ],
            'audioStartSeconds' => $audioStart,
            'nominalStartSeconds' => $nominalStart,
            'nominalEndSeconds' => $nominalEnd,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function word(string $text, float $start, float $end): array
    {
        return ['text' => $text, 'start' => $start, 'end' => $end, 'type' => 'word'];
    }
}
