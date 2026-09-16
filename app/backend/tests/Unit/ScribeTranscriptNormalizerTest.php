<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use Tests\TestCase;

class ScribeTranscriptNormalizerTest extends TestCase
{
    public function test_preserves_korean_word_boundaries_and_mixed_text(): void
    {
        foreach (['나는 학교에 갑니다.', '나는 Laravel 학교에 갑니다.', '나는 학교에 갑니다.'] as $text) {
            $words = array_map(fn (string $word, int $index): array => [
                'text' => $word, 'start' => $index * 0.2, 'end' => ($index + 1) * 0.2, 'type' => 'word',
            ], explode(' ', $text), array_keys(explode(' ', $text)));
            $transcript = $this->normalizer()->normalize(['words' => $words], 'kor', 3);
            $this->assertSame($text, $transcript->segments[0]->text);
        }
    }

    public function test_streaming_normalization_holds_an_unfinished_phrase_and_overlap_back(): void
    {
        $payload = ['words' => [
            ['text' => 'Hello.', 'start' => 0.1, 'end' => 1, 'type' => 'word'],
            ['text' => 'A', 'start' => 2, 'end' => 2.5, 'type' => 'word'],
            ['text' => 'longer', 'start' => 2.6, 'end' => 3, 'type' => 'word'],
            ['text' => 'phrase.', 'start' => 3.2, 'end' => 4, 'type' => 'word'],
        ]];
        $prefix = $this->normalizer()->normalize($payload, 'eng', 10, stableBeforeSeconds: 3.5);
        $complete = $this->normalizer()->normalize($payload, 'eng', 10);
        $this->assertSame(['Hello.'], array_column($prefix->segments, 'text'));
        $this->assertEquals($prefix->segments, array_slice($complete->segments, 0, 1));
        $this->assertSame('A longer phrase.', $complete->segments[1]->text);
    }

    public function test_it_converts_scribe_words_to_valid_webvtt_cues(): void
    {
        $transcript = $this->normalizer()->normalize([
            'language_code' => 'eng',
            'words' => [
                ['text' => 'Hello', 'start' => 0.1, 'end' => 0.4, 'type' => 'word'],
                ['text' => ' ', 'start' => 0.4, 'end' => 0.5, 'type' => 'spacing'],
                ['text' => 'world.', 'start' => 0.5, 'end' => 0.9, 'type' => 'word'],
                ['text' => 'After', 'start' => 2.0, 'end' => 2.3, 'type' => 'word'],
                ['text' => 'pause.', 'start' => 2.4, 'end' => 2.9, 'type' => 'word'],
            ],
        ], 'auto', 3.0);

        $this->assertSame('eng', $transcript->language);
        $this->assertSame(3.0, $transcript->durationSeconds);
        $this->assertSame("WEBVTT\n\ncue-0001\n00:00:00.100 --> 00:00:00.900\nHello world.\n\ncue-0002\n00:00:02.000 --> 00:00:02.900\nAfter pause.\n", $transcript->webVtt);
        $this->assertCount(2, $transcript->segments);
    }

    public function test_it_breaks_on_non_latin_sentence_punctuation(): void
    {
        $transcript = $this->normalizer()->normalize([
            'language_code' => 'ar',
            'words' => [
                ['text' => 'أهلا', 'start' => 0.0, 'end' => 0.3, 'type' => 'word'],
                ['text' => 'وسهلا', 'start' => 0.4, 'end' => 0.8, 'type' => 'word'],
                ['text' => 'بكم؟', 'start' => 0.9, 'end' => 1.2, 'type' => 'word'],
                ['text' => 'نبدأ', 'start' => 1.3, 'end' => 1.7, 'type' => 'word'],
                ['text' => 'الآن.', 'start' => 1.8, 'end' => 2.2, 'type' => 'word'],
            ],
        ], 'ara', 3.0);

        $this->assertSame('ara', $transcript->language);
        $this->assertCount(2, $transcript->segments);
        $this->assertSame('أهلا وسهلا بكم؟', $transcript->segments[0]->text);
        $this->assertSame('نبدأ الآن.', $transcript->segments[1]->text);
    }

    public function test_it_preserves_untimed_word_text_when_nearby_words_have_timings(): void
    {
        $transcript = $this->normalizer()->normalize([
            'language_code' => 'en',
            'words' => [
                ['text' => 'Hello', 'start' => 0.1, 'end' => 0.4, 'type' => 'word'],
                ['text' => 'untimed', 'type' => 'word'],
                ['text' => 'world.', 'start' => 0.5, 'end' => 0.9, 'type' => 'word'],
                ['text' => 'again', 'start' => 1.0, 'end' => 1.3, 'type' => 'word'],
                ['text' => 'trailing', 'type' => 'word'],
            ],
        ], 'eng', 2.0);

        $this->assertSame('Hello untimed world.', $transcript->segments[0]->text);
        $this->assertSame('again trailing', $transcript->segments[1]->text);
    }

    public function test_it_collapses_provider_artifact_spacing_for_no_space_scripts(): void
    {
        $transcript = $this->normalizer()->normalize([
            'language_code' => 'ja',
            'words' => [
                ['text' => "\u{65E5}", 'start' => 0.0, 'end' => 0.1, 'type' => 'word'],
                ['text' => "\u{672C}", 'start' => 0.1, 'end' => 0.2, 'type' => 'word'],
                ['text' => "\u{8A9E}", 'start' => 0.2, 'end' => 0.3, 'type' => 'word'],
                ['text' => "\u{3092}", 'start' => 0.3, 'end' => 0.4, 'type' => 'word'],
                ['text' => "\u{52C9}", 'start' => 0.4, 'end' => 0.5, 'type' => 'word'],
                ['text' => "\u{5F37}", 'start' => 0.5, 'end' => 0.6, 'type' => 'word'],
                ['text' => "\u{3059}\u{308B}\u{3002}", 'start' => 0.6, 'end' => 0.9, 'type' => 'word'],
            ],
        ], 'auto', 1.0);

        $this->assertSame('jpn', $transcript->language);
        $this->assertSame("\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3059}\u{308B}\u{3002}", $transcript->segments[0]->text);
        $this->assertStringContainsString("\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3059}\u{308B}\u{3002}", $transcript->webVtt);
    }

    public function test_it_breaks_per_character_runs_on_pause_or_punctuation_not_word_count(): void
    {
        // Japanese with one Scribe "word" per character and a small logical
        // pause mid-utterance. With the old per-word limit this split mid-word
        // after ~14 characters; the new segmenter breaks on the pause instead.
        $jp = "\u{53CB}\u{9054}"; // 友達 (tomodachi)
        $start = 0.0;
        $words = [];
        foreach (mb_str_split($jp.$jp.'。', 1, 'UTF-8') as $i => $char) {
            // Insert a single 1.0s pause before the second 友 to mark a boundary.
            $startOffset = $i === 2 ? 1.0 : 0.0;
            $start = $start + 0.1 + $startOffset;
            $words[] = ['text' => $char, 'start' => $start, 'end' => $start + 0.1, 'type' => 'word'];
        }

        $transcript = $this->normalizer()->normalize([
            'language_code' => 'ja',
            'words' => $words,
        ], 'auto', 3.0);

        $this->assertCount(2, $transcript->segments);
        $this->assertSame($jp, $transcript->segments[0]->text);
        $this->assertSame($jp."\u{3002}", $transcript->segments[1]->text);
    }

    public function test_it_prefers_clause_punctuation_when_a_hard_limit_trips(): void
    {
        // 20 English words with a comma after the 10th. The char limit is not
        // reached, so build a >6s duration instead; the break must snap to the
        // comma, not land on the 11th word.
        $words = [];
        $start = 0.0;
        for ($i = 1; $i <= 20; $i++) {
            $text = $i === 10 ? 'word,' : 'word';
            $end = $start + 0.5;
            $words[] = ['text' => $text, 'start' => $start, 'end' => $end, 'type' => 'word'];
            $start = $end; // back-to-back => no pause candidates, punctuation only
        }

        $transcript = $this->normalizer()->normalize([
            'language_code' => 'en',
            'words' => $words,
        ], 'eng', 12.0);

        $this->assertGreaterThan(1, count($transcript->segments));
        $this->assertSame(9, substr_count($transcript->segments[0]->text, ' '));
        $this->assertSame('word,', trim(explode(' ', $transcript->segments[0]->text)[9]));
    }

    public function test_it_counts_characters_on_artifact_stripped_text_for_no_space_scripts(): void
    {
        // 90 single CJK characters back-to-back: char limit (84 on stripped
        // text) should trip, producing >1 cue. Under the old code the count
        // included artifact spaces and tripped even earlier, but the kept
        // guarantee is simply that the break is not driven by word count.
        $chars = implode('', array_fill(0, 90, "\u{65E5}")); // 日 * 90
        $start = 0.0;
        $words = [];
        foreach (mb_str_split($chars, 1, 'UTF-8') as $char) {
            $end = $start + 0.05;
            $words[] = ['text' => $char, 'start' => $start, 'end' => $end, 'type' => 'word'];
            $start = $end;
        }

        $transcript = $this->normalizer()->normalize([
            'language_code' => 'ja',
            'words' => $words,
        ], 'auto', 6.0);

        $this->assertGreaterThan(1, count($transcript->segments));
        $this->assertSame(90, mb_strlen(implode('', array_map(fn ($s) => $s->text, $transcript->segments)), 'UTF-8'));
    }

    public function test_it_rejects_missing_word_timings(): void
    {
        try {
            $this->normalizer()->normalize([
                'words' => [
                    ['text' => 'Broken', 'type' => 'word'],
                ],
            ], 'eng', 1.0);
            $this->fail('Expected missing word timing to fail.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('invalid_word_timing', $exception->context['reason']);
        }
    }

    private function normalizer(): ScribeTranscriptNormalizer
    {
        return new ScribeTranscriptNormalizer;
    }
}
