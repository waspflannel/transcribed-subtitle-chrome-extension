<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Transcription\WebVttTranscriptParser;
use Tests\TestCase;

class WebVttTranscriptParserTest extends TestCase
{
    public function test_it_normalizes_webvtt_and_parses_segments(): void
    {
        $parsed = $this->parser()->parse(
            "\xEF\xBB\xBFWEBVTT\r\n\r\ncue-1\r\n00:00:00.500 --> 00:00:02.100 align:start\r\n<voice>first &amp; transcript segment</voice>\r\n\r\ncue-2\r\n00:00:02.400 --> 00:00:04.000\r\nsecond transcript segment\r\n",
        );

        $this->assertSame(
            "WEBVTT\n\ncue-1\n00:00:00.500 --> 00:00:02.100 align:start\n<voice>first &amp; transcript segment</voice>\n\ncue-2\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n",
            $parsed['webVtt'],
        );
        $this->assertCount(2, $parsed['segments']);
        $this->assertSame(0.5, $parsed['segments'][0]->startSeconds);
        $this->assertSame(2.1, $parsed['segments'][0]->endSeconds);
        $this->assertSame('first & transcript segment', $parsed['segments'][0]->text);
        $this->assertSame('second transcript segment', $parsed['segments'][1]->text);
    }

    public function test_it_rejects_blocks_without_timing_lines(): void
    {
        $this->assertInvalidWebVttReason('missing_timing_line', "WEBVTT\n\nbad block\n");
    }

    public function test_it_rejects_overlapping_timing(): void
    {
        $this->assertInvalidWebVttReason(
            'overlapping_timing',
            "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nfirst\n\n00:00:01.500 --> 00:00:03.000\nsecond\n",
        );
    }

    private function parser(): WebVttTranscriptParser
    {
        return new WebVttTranscriptParser;
    }

    private function assertInvalidWebVttReason(string $reason, string $webVtt): void
    {
        try {
            $this->parser()->parse($webVtt);
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame($reason, $exception->context['reason'] ?? null);

            return;
        }

        $this->fail('Expected invalid WebVTT to throw a stable transcription exception.');
    }
}
