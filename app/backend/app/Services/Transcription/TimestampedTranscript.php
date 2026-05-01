<?php

namespace App\Services\Transcription;

class TimestampedTranscript
{
    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    public function __construct(
        public readonly ?string $language,
        public readonly ?float $durationSeconds,
        public readonly array $segments,
    ) {}
}
