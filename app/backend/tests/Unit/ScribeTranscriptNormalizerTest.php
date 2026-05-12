<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use App\Services\Transcription\WebVttTranscriptParser;
use Tests\TestCase;

class ScribeTranscriptNormalizerTest extends TestCase
{
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
        return new ScribeTranscriptNormalizer(new WebVttTranscriptParser);
    }
}
