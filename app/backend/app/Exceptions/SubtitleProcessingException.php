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

    /**
     * @param  array<string, mixed>  $context
     */
    public static function enrichmentFailed(string $message = 'Subtitle enrichment failed.', array $context = [], ?Throwable $previous = null): self
    {
        return new self('enrichment_failed', $message, 502, $context, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function rateLimited(string $message = 'Subtitle generation is temporarily rate limited.', array $context = [], ?Throwable $previous = null): self
    {
        return new self('rate_limited', $message, 429, $context, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function providerUnavailable(string $message = 'Subtitle provider is temporarily unavailable.', array $context = [], ?Throwable $previous = null): self
    {
        return new self('provider_unavailable', $message, 503, $context, $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function lyricsCorrectionInProgress(array $context = []): self
    {
        return new self('lyrics_correction_in_progress', 'A pasted-lyrics correction is already in progress.', 409, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function lyricsDoNotMatch(array $context = []): self
    {
        return new self('lyrics_do_not_match', 'These lyrics do not seem to match this song.', 422, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function lyricsIncomplete(array $context = []): self
    {
        return new self('lyrics_incomplete', 'These lyrics do not cover the complete song.', 422, $context);
    }

    public static function lyricsTrackChanged(): self
    {
        return new self('lyrics_correction_in_progress', 'The subtitle track changed. Refresh the panel and try again.', 409, ['reason' => 'stale_track']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function lyricsCorrectionFailed(array $context = [], ?Throwable $previous = null): self
    {
        return new self('lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', 422, $context, $previous);
    }

    /**
     * Transient transport-level provider failures (rate limits, 5xx, timeouts)
     * are weather, not programming errors — they are safe to retry.
     */
    public function isTransient(): bool
    {
        return in_array($this->publicCode, ['rate_limited', 'provider_unavailable'], true);
    }
}
