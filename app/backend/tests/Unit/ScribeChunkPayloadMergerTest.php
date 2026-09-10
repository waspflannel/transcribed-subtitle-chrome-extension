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

    public function test_reconciles_disagreeing_boundary_timestamps_when_both_midpoints_survive(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('frontera.', 99.4, 100.2)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('frontera.', 1.8, 2.6)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertCount(1, $merged['words']);
        $this->assertSame('frontera.', $merged['words'][0]['text']);
        $this->assertSame(99.4, $merged['words'][0]['start']);
        $this->assertSame(100.2, $merged['words'][0]['end']);
    }

    public function test_reconciles_disagreeing_boundary_timestamps_when_both_midpoints_are_dropped(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('frontera.', 99.8, 100.6)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('frontera.', 1.4, 2.2)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertCount(1, $merged['words']);
        $this->assertSame(99.8, $merged['words'][0]['start']);
        $this->assertSame(100.6, $merged['words'][0]['end']);
    }

    public function test_keeps_repeated_boundary_lyrics_at_distinct_times(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('yeah', 99.6, 99.9)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('yeah', 2.0, 2.3)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['yeah', 'yeah'], array_column($merged['words'], 'text'));
        $this->assertSame([99.6, 100.0], array_column($merged['words'], 'start'));
    }

    public function test_matches_repeated_boundary_lyrics_one_to_one_in_order(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('la', 99.0, 99.5),
                    $this->word('la', 99.8, 100.3),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('la', 1.1, 1.6),
                    $this->word('la', 1.9, 2.4),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertCount(2, $merged['words']);
        $this->assertSame(['la', 'la'], array_column($merged['words'], 'text'));
        $this->assertSame([99.0, 99.9], array_column($merged['words'], 'start'));
    }

    public function test_does_not_reconcile_unmatched_text_by_timing_alone(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('izquierda', 99.0, 99.4)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('derecha', 1.0, 1.4)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['izquierda'], array_column($merged['words'], 'text'));
    }

    public function test_reconciled_boundary_word_keeps_its_selected_untimed_attachment(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    ['text' => 'izquierda-glue', 'type' => 'word'],
                    $this->word('frontera.', 99.8, 100.6),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    ['text' => 'derecha-glue', 'type' => 'word'],
                    $this->word('frontera.', 1.4, 2.2),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['izquierda-glue', 'frontera.'], array_column($merged['words'], 'text'));
    }

    public function test_matches_the_overlapping_occurrence_when_a_repeat_is_nearby(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('la', 99.8, 99.95),
                    $this->word('la', 100.05, 100.2),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('la', 2.06, 2.21)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['la', 'la'], array_column($merged['words'], 'text'));
        $this->assertSame([99.8, 100.06], array_column($merged['words'], 'start'));
    }

    public function test_places_matched_winners_at_their_canonical_occurrence_positions(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('first', 99.8, 100.4),
                    $this->word('second', 99.95, 100.2),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('first', 1.85, 2.4),
                    $this->word('second', 1.96, 2.02),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['first', 'second'], array_column($merged['words'], 'text'));
        $this->assertSame([99.85, 99.95], array_column($merged['words'], 'start'));
    }

    public function test_keeps_unmatched_neighbors_and_selected_attachments_in_order(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('before', 98.0, 98.2),
                    $this->word('first', 99.8, 100.4),
                    ['text' => 'left-second-glue', 'type' => 'word'],
                    $this->word('second', 99.95, 100.2),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    ['text' => 'right-first-glue', 'type' => 'word'],
                    $this->word('first', 1.85, 2.4),
                    $this->word('second', 1.96, 2.02),
                    $this->word('after', 2.5, 2.8),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(
            ['before', 'right-first-glue', 'first', 'left-second-glue', 'second', 'after'],
            array_column($merged['words'], 'text'),
        );
    }

    public function test_keeps_a_right_side_predecessor_before_a_matched_word(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('second', 100.4, 100.8)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('first', 2.1, 2.3),
                    $this->word('second', 2.4, 2.8),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['first', 'second'], array_column($merged['words'], 'text'));
        $this->assertSame([100.1, 100.4], array_column($merged['words'], 'start'));
    }

    public function test_keeps_right_side_words_interleaved_between_matched_anchors(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('first', 99.8, 100.3),
                    $this->word('second', 100.6, 100.9),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('first', 1.8, 2.3),
                    $this->word('middle', 2.35, 2.55),
                    $this->word('second', 2.6, 2.9),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(
            ['first', 'middle', 'second'],
            array_column($merged['words'], 'text'),
        );
    }

    public function test_keeps_ambiguous_overlapping_repeats_with_midpoint_ownership(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('la', 99.0, 99.8),
                    $this->word('la', 99.3, 99.9),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('la', 1.4, 1.7)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['la', 'la'], array_column($merged['words'], 'text'));
        $this->assertSame([99.0, 99.3], array_column($merged['words'], 'start'));
    }

    public function test_keeps_crossing_matches_in_existing_source_order(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('first', 99.8, 100.3),
                    $this->word('second', 99.9, 100.4),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('second', 1.9, 2.2),
                    $this->word('first', 1.8, 2.3),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['second', 'first'], array_column($merged['words'], 'text'));
    }

    public function test_keeps_words_that_only_touch_at_the_boundary(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [$this->word('touch', 99.6, 100.0)],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [$this->word('touch', 2.0, 2.3)],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertCount(2, $merged['words']);
        $this->assertSame([99.6, 100.0], array_column($merged['words'], 'start'));
    }

    public function test_keeps_dense_repeats_when_one_chunk_has_an_extra_occurrence(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('la', 99.0, 99.5),
                    $this->word('la', 99.55, 99.9),
                    $this->word('la', 100.0, 100.35),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('la', 1.1, 1.55),
                    $this->word('la', 2.1, 2.45),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['la', 'la', 'la'], array_column($merged['words'], 'text'));
        $this->assertSame([99.0, 99.55, 100.1], array_column($merged['words'], 'start'));
    }

    public function test_reconciles_each_adjacent_boundary_in_order(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('uno', 1.0, 1.4),
                    $this->word('primero', 99.4, 100.2),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: 100.0,
            ),
            $this->chunk(
                words: [
                    $this->word('primero', 1.8, 2.6),
                    $this->word('medio', 50.0, 50.4),
                    $this->word('segundo', 101.8, 102.6),
                ],
                audioStart: 98.0,
                nominalStart: 100.0,
                nominalEnd: 200.0,
            ),
            $this->chunk(
                words: [
                    $this->word('segundo', 1.4, 2.2),
                    $this->word('fin', 3.0, 3.4),
                ],
                audioStart: 198.0,
                nominalStart: 200.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(
            ['uno', 'primero', 'medio', 'segundo', 'fin'],
            array_column($merged['words'], 'text'),
        );
    }

    public function test_preserves_single_chunk_word_order_when_timestamps_disagree(): void
    {
        $merged = $this->merger()->merge([
            $this->chunk(
                words: [
                    $this->word('first', 1.1, 1.4),
                    $this->word('second', 1.0, 1.5),
                ],
                audioStart: 0.0,
                nominalStart: 0.0,
                nominalEnd: null,
            ),
        ]);

        $this->assertSame(['first', 'second'], array_column($merged['words'], 'text'));
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

    public function test_language_ties_keep_the_first_supported_chunk_language(): void
    {
        // Identical speech duration breaks ties by original chunk order.
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

        $this->assertSame('spa', $merged['language_code']);
    }

    public function test_language_selection_uses_confidence_weighted_owned_speech_across_chunks(): void
    {
        $intro = $this->chunk([$this->word('intro', 0.0, 1.0)], 0.0, 0.0, 100.0, 'eng');
        $intro['payload']['language_probability'] = 0.9;
        $main = $this->chunk([
            $this->word('overlap', 0.0, 1.0), $this->word('speech', 2.0, 12.0),
        ], 98.0, 100.0, null, 'es');
        $main['payload']['language_probability'] = 0.8;

        $this->assertSame('spa', $this->merger()->merge([$intro, $main])['language_code']);
        $main['payload']['language_probability'] = 0.01;
        $this->assertSame('eng', $this->merger()->merge([$intro, $main])['language_code']);
        $intro['payload']['language_code'] = 'unsupported-private-text';
        $this->assertSame('spa', $this->merger()->merge([$intro, $main])['language_code']);
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
