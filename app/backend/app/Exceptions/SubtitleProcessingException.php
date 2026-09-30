<?php

namespace App\Exceptions;

use App\Services\Subtitles\ProviderExceptionPolicy;
use Exception;
use Throwable;

class SubtitleProcessingException extends Exception
{
    public readonly array $context;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $publicCode,
        string $publicMessage,
        public readonly int $status,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        $this->context = $previous === null ? $context : [...$context, ...ProviderExceptionPolicy::diagnostics($previous)];
        // Previous provider exceptions can contain complete response bodies.
        // Ordinary logs and failed_jobs must receive only these safe diagnostics.
        parent::__construct($publicMessage);
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
        if ($previous !== null) {
            $diagnostics = ProviderExceptionPolicy::diagnostics($previous);
            if (isset($diagnostics['provider_error_code'])) {
                return self::enrichmentFailed('Subtitle AI provider quota is exhausted.', [
                    ...$context,
                    'reason' => 'provider_quota_exhausted',
                    ...$diagnostics,
                ], $previous);
            }
        }

        return new self('rate_limited', $message, 429, $context, $previous);
    }

    public function context(): array
    {
        return ['error_code' => $this->publicCode, ...$this->context];
    }

    public function __toString(): string
    {
        // Laravel's database failed-job store serializes the exception as text.
        $diagnostics = array_intersect_key($this->context(), array_flip([
            'error_code', 'provider', 'exception', 'cause_exception', 'status',
            'provider_request_id', 'provider_error_code', 'reason',
        ]));

        return parent::__toString()."\nSafe diagnostics: ".json_encode($diagnostics, JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function providerUnavailable(string $message = 'Subtitle provider is temporarily unavailable.', array $context = [], ?Throwable $previous = null): self
    {
        return new self('provider_unavailable', $message, 503, $context, $previous);
    }

    /**
     * Queue publication failed after the job transaction committed. The
     * caller can persist this as a terminal, retryable job outcome.
     *
     * @param  array<string, mixed>  $context
     */
    public static function queuePublicationFailed(array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            'queue_publication_failed',
            'Generation could not be queued. Try again.',
            503,
            $context,
            $previous,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function generationNotCancellable(array $context = []): self
    {
        return new self(
            'generation_not_cancellable',
            'Only queued or running subtitle generations can be cancelled.',
            409,
            $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function lyricsCorrectionInProgress(array $context = []): self
    {
        return new self('lyrics_correction_in_progress', 'A pasted-lyrics correction is already in progress.', 409, $context);
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
