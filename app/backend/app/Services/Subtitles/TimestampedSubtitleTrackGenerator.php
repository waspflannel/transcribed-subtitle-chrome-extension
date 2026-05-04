<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
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
        $cues = $this->cues($transcript);

        return SubtitleTrack::create([
            'public_id' => (string) Str::uuid(),
            'subtitle_job_id' => $job->id,
            'youtube_video_id' => $job->youtube_video_id,
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'processing_version' => $job->processing_version,
            'generated_at' => $generatedAt,
            'expires_at' => $generatedAt->copy()->addDays(30),
            'web_vtt' => $this->validatedWebVtt($transcript),
            'cues' => $cues,
        ]);
    }

    /**
     * @return array<int, array{cueId: string, index: int, startMs: int, endMs: int, sourceText: string, translatedText: string, tokens: array<int, mixed>}>
     */
    private function cues(TimestampedTranscript $transcript): array
    {
        $previousEndMs = null;

        return collect($transcript->segments)
            ->map(function (TimestampedTranscriptSegment $segment, int $index) use (&$previousEndMs): array {
                $sourceText = $this->normalizeText($segment->text);
                $startMs = (int) round($segment->startSeconds * 1000);
                $endMs = (int) round($segment->endSeconds * 1000);

                $this->validateCue($sourceText, $startMs, $endMs, $index, $previousEndMs);
                $previousEndMs = $endMs;

                return [
                    'cueId' => sprintf('cue-%04d', $index + 1),
                    'index' => $index,
                    'startMs' => $startMs,
                    'endMs' => $endMs,
                    'sourceText' => $sourceText,
                    // Phase 05 is source-only; Phase 06 replaces this with real translation.
                    'translatedText' => $sourceText,
                    'tokens' => [],
                ];
            })
            ->values()
            ->all();
    }

    private function validatedWebVtt(TimestampedTranscript $transcript): string
    {
        $webVtt = trim($transcript->webVtt);

        if ($webVtt === '' || ! str_starts_with($webVtt, 'WEBVTT')) {
            $this->failInvalidCue('invalid_web_vtt');
        }

        if ($transcript->segments === []) {
            $this->failInvalidCue('empty_cue_output');
        }

        return $webVtt."\n";
    }

    private function validateCue(
        string $sourceText,
        int $startMs,
        int $endMs,
        int $cueIndex,
        ?int $previousEndMs,
    ): void {
        if ($sourceText === '') {
            $this->failInvalidCue('empty_source_text', ['cue_index' => $cueIndex]);
        }

        if ($startMs < 0 || $endMs <= $startMs) {
            $this->failInvalidCue('invalid_timing', [
                'cue_index' => $cueIndex,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
            ]);
        }

        if ($previousEndMs !== null && $startMs < $previousEndMs) {
            $this->failInvalidCue('overlapping_timing', [
                'cue_index' => $cueIndex,
                'start_ms' => $startMs,
                'previous_end_ms' => $previousEndMs,
            ]);
        }
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws SubtitleProcessingException
     */
    private function failInvalidCue(string $reason, array $context = []): never
    {
        throw SubtitleProcessingException::transcriptionFailed(
            'Transcription could not be converted into valid subtitle cues.',
            [
                'reason' => $reason,
                ...$context,
            ],
        );
    }
}
