<?php

namespace App\Services\Transcription;

class TimestampedTranscriptSegment
{
    public function __construct(
        public readonly float $startSeconds,
        public readonly float $endSeconds,
        public readonly string $text,
    ) {}
}
