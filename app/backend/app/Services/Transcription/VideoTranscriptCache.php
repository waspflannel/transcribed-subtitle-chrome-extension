<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Models\CachedVideoTranscript;
use Laravel\Ai\Enums\Lab;

/**
 * Caches transcripts per video so re-generating the same YouTube video skips
 * acquire, optimize, and transcribe entirely. Keyed by
 * (youtube_video_id, requested_source_language, transcription model) --
 * transcripts are user-independent and derive only from public YouTube audio.
 *
 * `subtitles.transcript_cache.ttl_days` <= 0 disables both reads and writes.
 */
class VideoTranscriptCache
{
    public function find(string $youtubeVideoId, string $requestedSourceLanguage): ?CachedVideoTranscript
    {
        if ($this->ttlDays() <= 0) {
            return null;
        }

        $entry = CachedVideoTranscript::query()
            ->where('youtube_video_id', $youtubeVideoId)
            ->where('requested_source_language', $requestedSourceLanguage)
            ->where('transcription_model', $this->transcriptionModel())
            ->where('expires_at', '>', now())
            ->first();

        return $entry;
    }

    public function store(
        string $youtubeVideoId,
        string $requestedSourceLanguage,
        TimestampedTranscript $transcript,
        int $audioDurationSeconds,
    ): void {
        if ($this->ttlDays() <= 0) {
            return;
        }

        CachedVideoTranscript::query()->updateOrCreate(
            [
                'youtube_video_id' => $youtubeVideoId,
                'requested_source_language' => $requestedSourceLanguage,
                'transcription_model' => $this->transcriptionModel(),
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

    private function transcriptionModel(): string
    {
        return trim((string) config('ai.providers.'.Lab::ElevenLabs->value.'.models.transcription.default'));
    }

    private function ttlDays(): int
    {
        return (int) config('subtitles.transcript_cache.ttl_days', 30);
    }
}
