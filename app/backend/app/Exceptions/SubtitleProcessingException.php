<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class SubtitleProcessingException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $publicCode,
        string $publicMessage,
        public readonly int $status,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function audioUnavailable(string $message = 'Audio is unavailable for this video.', array $context = []): self
    {
        return new self('audio_unavailable', $message, 422, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function audioAcquisitionFailed(string $message = 'Audio acquisition failed.', array $context = [], ?Throwable $previous = null): self
    {
        return new self('audio_acquisition_failed', $message, 502, $context, $previous);
    }

    public static function videoTooLong(int $durationSeconds, int $maxDurationSeconds): self
    {
        return new self(
            'video_too_long',
            'Video exceeds the 60 minute limit.',
            422,
            [
                'duration_seconds' => $durationSeconds,
                'max_duration_seconds' => $maxDurationSeconds,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function transcriptionFailed(string $message = 'Transcription failed.', array $context = [], ?Throwable $previous = null): self
    {
        return new self('transcription_failed', $message, 502, $context, $previous);
    }
}
