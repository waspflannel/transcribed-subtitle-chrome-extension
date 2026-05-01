<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Transcription\TimestampedTranscriptNormalizer;
use Tests\TestCase;

class TimestampedTranscriptNormalizerTest extends TestCase
{
    public function test_it_sorts_and_keeps_valid_timestamped_segments(): void
    {
        $transcript = (new TimestampedTranscriptNormalizer)->normalize('ar', 4.2, [
            ['text' => 'second', 'start' => 2.0, 'end' => 3.0],
            ['text' => 'first', 'start' => 0.5, 'end' => 1.25],
            ['text' => '', 'start' => 3.0, 'end' => 4.0],
            ['text' => 'bad', 'start' => 4.0, 'end' => 4.0],
        ]);

        $this->assertSame('ar', $transcript->language);
        $this->assertSame(4.2, $transcript->durationSeconds);
        $this->assertCount(2, $transcript->segments);
        $this->assertSame('first', $transcript->segments[0]->text);
        $this->assertSame(0.5, $transcript->segments[0]->startSeconds);
        $this->assertSame('second', $transcript->segments[1]->text);
    }

    public function test_it_rejects_transcripts_without_valid_segments(): void
    {
        $this->expectException(SubtitleProcessingException::class);
        $this->expectExceptionMessage('Transcription did not return timestamped segments.');

        (new TimestampedTranscriptNormalizer)->normalize('ar', null, [
            ['text' => '', 'start' => 0, 'end' => 1],
            ['text' => 'invalid', 'start' => 2, 'end' => 1],
        ]);
    }
}
