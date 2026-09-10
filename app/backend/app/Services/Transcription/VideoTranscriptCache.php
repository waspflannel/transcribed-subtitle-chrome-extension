<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Models\CachedVideoTranscript;
use App\Support\SubtitleProcessingVersion;
use Laravel\Ai\Enums\Lab;

/**
 * Caches transcripts per video so re-generating the same YouTube video skips
 * acquire, optimize, and transcribe entirely. Keyed by
 * (youtube_video_id, requested_source_language, transcription model, and
 * processing version). Ingestion mode and normalized vocabulary hints also
 * distinguish entries. Hinted entries are scoped to the requesting user.
 *
 * `subtitles.transcript_cache.ttl_days` <= 0 disables both reads and writes.
 */
class VideoTranscriptCache
{
    public function find(string $youtubeVideoId, string $requestedSourceLanguage, array $vocabularyHints = [], string $ingestionMode = 'upload', ?int $userId = null): ?CachedVideoTranscript
    {
        if ($this->ttlDays() <= 0 || ($vocabularyHints !== [] && $userId === null)) {
            return null;
        }

        $entry = CachedVideoTranscript::query()
            ->where('youtube_video_id', $youtubeVideoId)
            ->where('requested_source_language', $requestedSourceLanguage)
            ->where('transcription_model', $this->transcriptionModel($vocabularyHints, $ingestionMode, $userId))
            ->where('expires_at', '>', now())
            ->first();

        return $entry;
    }

    public function store(
        string $youtubeVideoId,
        string $requestedSourceLanguage,
        TimestampedTranscript $transcript,
        int $audioDurationSeconds,
        array $vocabularyHints = [],
        string $ingestionMode = 'upload',
        ?int $userId = null,
    ): void {
        if ($this->ttlDays() <= 0 || ($vocabularyHints !== [] && $userId === null)) {
            return;
        }

        CachedVideoTranscript::query()->updateOrCreate(
            [
                'youtube_video_id' => $youtubeVideoId,
                'requested_source_language' => $requestedSourceLanguage,
                'transcription_model' => $this->transcriptionModel($vocabularyHints, $ingestionMode, $userId),
            ],
            [
                'audio_duration_seconds' => $audioDurationSeconds,
                'payload' => [
                    'language' => $transcript->language,
                    'durationSeconds' => $transcript->durationSeconds,
                    'webVtt' => $transcript->webVtt,
                    'segments' => array_map(
                        fn (TimestampedTranscriptSegment $segment): array => [
                            'startSeconds' => $segment->startSeconds,
                            'endSeconds' => $segment->endSeconds,
                            'text' => $segment->text,
                        ],
                        $transcript->segments,
                    ),
                ],
                'expires_at' => now()->addDays($this->ttlDays()),
            ],
        );
    }

    public function transcript(CachedVideoTranscript $entry): TimestampedTranscript
    {
        $payload = $entry->payload;
        $segments = $payload['segments'] ?? null;

        if (! is_array($segments) || ! is_string($payload['language'] ?? null) || ! is_string($payload['webVtt'] ?? null)) {
            throw SubtitleProcessingException::transcriptionFailed('Cached transcript payload is unusable.', [
                'reason' => 'invalid_cached_transcript',
                'cached_video_transcript_id' => $entry->id,
            ]);
        }

        return new TimestampedTranscript(
            language: $payload['language'],
            durationSeconds: is_numeric($payload['durationSeconds'] ?? null)
                ? (float) $payload['durationSeconds']
                : null,
            segments: array_map(
                fn (array $segment): TimestampedTranscriptSegment => new TimestampedTranscriptSegment(
                    startSeconds: (float) $segment['startSeconds'],
                    endSeconds: (float) $segment['endSeconds'],
                    text: (string) $segment['text'],
                ),
                array_values($segments),
            ),
            webVtt: $payload['webVtt'],
        );
    }

    private function transcriptionModel(array $vocabularyHints, string $ingestionMode, ?int $userId): string
    {
        $model = SubtitleProcessingVersion::transcriptCacheModel(
            trim((string) config('ai.providers.'.Lab::ElevenLabs->value.'.models.transcription.default')),
        );
        $variant = SubtitleProcessingVersion::transcriptionOptionsHash($vocabularyHints, $ingestionMode);

        // User-provided hints must never seed another user's cached transcript.
        return $variant === '' ? $model : $model.':'.hash('sha256', $variant.($vocabularyHints === [] ? '' : ':user:'.$userId));
    }

    private function ttlDays(): int
    {
        return (int) config('subtitles.transcript_cache.ttl_days', 30);
    }
}
