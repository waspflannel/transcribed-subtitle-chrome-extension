<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use Illuminate\Support\Str;

class TimestampedSubtitleTrackGenerator
{
    public function generate(SubtitleJob $job, TimestampedTranscript $transcript): SubtitleTrack
    {
        $generatedAt = now();

        return SubtitleTrack::create([
            'public_id' => (string) Str::uuid(),
            'subtitle_job_id' => $job->id,
            'youtube_video_id' => $job->youtube_video_id,
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'processing_version' => $job->processing_version,
            'detected_dialect_label' => null,
            'detected_dialect_confidence' => null,
            'generated_at' => $generatedAt,
            'expires_at' => $generatedAt->copy()->addDays(30),
            'cues' => $this->cues($transcript),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cues(TimestampedTranscript $transcript): array
    {
        return collect($transcript->segments)
            ->values()
            ->map(fn (TimestampedTranscriptSegment $segment, int $index): array => [
                'cueId' => sprintf('cue-%04d', $index + 1),
                'index' => $index,
                'startMs' => (int) round($segment->startSeconds * 1000),
                'endMs' => (int) round($segment->endSeconds * 1000),
                'sourceText' => $segment->text,
                // Phase 04 proves timing; Phase 06 replaces this with real translation.
                'translatedText' => $segment->text,
                'tokens' => [],
            ])
            ->all();
    }
}
