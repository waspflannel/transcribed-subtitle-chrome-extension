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
    private const TARGET_MIN_CUE_MS = 1500;

    private const TARGET_MAX_CUE_MS = 7000;

    private const SOFT_MAX_SOURCE_CHARS = 120;

    private const HARD_MAX_SOURCE_CHARS = 180;

    private const MIN_SPLIT_CUE_MS = 1000;

    private const MAX_MERGE_GAP_MS = 250;

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
            'cues' => $cues,
        ]);
    }

    /**
     * @return array<int, array{cueId: string, index: int, startMs: int, endMs: int, sourceText: string, translatedText: string, tokens: array<int, mixed>}>
     */
    private function cues(TimestampedTranscript $transcript): array
    {
        return collect($this->segmentTranscript($transcript))
            ->map(fn (array $cue, int $index): array => [
                'cueId' => sprintf('cue-%04d', $index + 1),
                'index' => $index,
                'startMs' => $cue['startMs'],
                'endMs' => $cue['endMs'],
                'sourceText' => $cue['sourceText'],
                // Phase 05 is source-only; Phase 06 replaces this with real translation.
                'translatedText' => $cue['sourceText'],
                'tokens' => [],
            ])
            ->all();
    }

    /**
     * @return array<int, array{startMs: int, endMs: int, sourceText: string}>
     */
    private function segmentTranscript(TimestampedTranscript $transcript): array
    {
        $drafts = [];

        foreach ($transcript->segments as $index => $segment) {
            $drafts[] = $this->draftFromSegment($segment, $index);
        }

        usort(
            $drafts,
            fn (array $first, array $second): int => $first['startMs'] <=> $second['startMs'],
        );

        $this->validateCueDrafts($drafts, enforceTextLength: false);

        $splitDrafts = [];

        foreach ($drafts as $draft) {
            array_push($splitDrafts, ...$this->splitDraftWhenSafe($draft));
        }

        $mergedDrafts = $this->mergeShortAdjacentDrafts($splitDrafts);
        $this->validateCueDrafts($mergedDrafts);

        return $mergedDrafts;
    }

    /**
     * @return array{startMs: int, endMs: int, sourceText: string}
     */
    private function draftFromSegment(TimestampedTranscriptSegment $segment, int $segmentIndex): array
    {
        $sourceText = $this->normalizeText($segment->text);
        $startMs = (int) round($segment->startSeconds * 1000);
        $endMs = (int) round($segment->endSeconds * 1000);

        if ($sourceText === '') {
            $this->failInvalidCue('empty_source_text', ['segment_index' => $segmentIndex]);
        }

        if ($startMs < 0 || $endMs <= $startMs) {
            $this->failInvalidCue('invalid_timing', [
                'segment_index' => $segmentIndex,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
            ]);
        }

        return [
            'startMs' => $startMs,
            'endMs' => $endMs,
            'sourceText' => $sourceText,
        ];
    }

    /**
     * @param  array{startMs: int, endMs: int, sourceText: string}  $draft
     * @return array<int, array{startMs: int, endMs: int, sourceText: string}>
     */
    private function splitDraftWhenSafe(array $draft): array
    {
        $durationMs = $draft['endMs'] - $draft['startMs'];
        $sourceLength = $this->textLength($draft['sourceText']);
        $targetChunkCount = max(
            (int) ceil($durationMs / self::TARGET_MAX_CUE_MS),
            (int) ceil($sourceLength / self::SOFT_MAX_SOURCE_CHARS),
        );

        if ($targetChunkCount <= 1 || $durationMs < $targetChunkCount * self::MIN_SPLIT_CUE_MS) {
            return [$draft];
        }

        $chunks = $this->splitTextIntoChunks($draft['sourceText'], $targetChunkCount);

        if (count($chunks) <= 1) {
            return [$draft];
        }

        $splitDrafts = [];
        $chunkCount = count($chunks);

        foreach ($chunks as $index => $chunk) {
            $startMs = $draft['startMs'] + (int) round($durationMs * ($index / $chunkCount));
            $endMs = $draft['startMs'] + (int) round($durationMs * (($index + 1) / $chunkCount));

            if ($endMs <= $startMs) {
                return [$draft];
            }

            $splitDrafts[] = [
                'startMs' => $startMs,
                'endMs' => $endMs,
                'sourceText' => $chunk,
            ];
        }

        return $splitDrafts;
    }

    /**
     * @return array<int, string>
     */
    private function splitTextIntoChunks(string $sourceText, int $targetChunkCount): array
    {
        $words = preg_split('/\s+/u', $sourceText, flags: PREG_SPLIT_NO_EMPTY);

        if (! is_array($words) || count($words) < 2) {
            return [$sourceText];
        }

        $targetChunkLength = max(1, (int) ceil($this->textLength($sourceText) / $targetChunkCount));
        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (
                $current !== ''
                && count($chunks) < $targetChunkCount - 1
                && $this->textLength($candidate) > $targetChunkLength
            ) {
                $chunks[] = $current;
                $current = $word;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  array<int, array{startMs: int, endMs: int, sourceText: string}>  $drafts
     * @return array<int, array{startMs: int, endMs: int, sourceText: string}>
     */
    private function mergeShortAdjacentDrafts(array $drafts): array
    {
        $merged = [];

        foreach ($drafts as $draft) {
            if ($merged === []) {
                $merged[] = $draft;

                continue;
            }

            $lastIndex = array_key_last($merged);
            $last = $merged[$lastIndex];

            if ($this->shouldMerge($last, $draft)) {
                $merged[$lastIndex] = [
                    'startMs' => $last['startMs'],
                    'endMs' => $draft['endMs'],
                    'sourceText' => $last['sourceText'].' '.$draft['sourceText'],
                ];

                continue;
            }

            $merged[] = $draft;
        }

        return $merged;
    }

    /**
     * @param  array{startMs: int, endMs: int, sourceText: string}  $first
     * @param  array{startMs: int, endMs: int, sourceText: string}  $second
     */
    private function shouldMerge(array $first, array $second): bool
    {
        $gapMs = $second['startMs'] - $first['endMs'];
        $combinedDurationMs = $second['endMs'] - $first['startMs'];
        $combinedText = $first['sourceText'].' '.$second['sourceText'];

        return $gapMs >= 0
            && $gapMs <= self::MAX_MERGE_GAP_MS
            && ($this->durationMs($first) < self::TARGET_MIN_CUE_MS || $this->durationMs($second) < self::TARGET_MIN_CUE_MS)
            && $combinedDurationMs <= self::TARGET_MAX_CUE_MS
            && $this->textLength($combinedText) <= self::SOFT_MAX_SOURCE_CHARS;
    }

    /**
     * @param  array<int, array{startMs: int, endMs: int, sourceText: string}>  $drafts
     */
    private function validateCueDrafts(array $drafts, bool $enforceTextLength = true): void
    {
        if ($drafts === []) {
            $this->failInvalidCue('empty_cue_output');
        }

        $previousEndMs = null;

        foreach ($drafts as $index => $draft) {
            if ($draft['sourceText'] === '') {
                $this->failInvalidCue('empty_source_text', ['cue_index' => $index]);
            }

            if ($draft['startMs'] < 0 || $draft['endMs'] <= $draft['startMs']) {
                $this->failInvalidCue('invalid_timing', [
                    'cue_index' => $index,
                    'start_ms' => $draft['startMs'],
                    'end_ms' => $draft['endMs'],
                ]);
            }

            if ($previousEndMs !== null && $draft['startMs'] < $previousEndMs) {
                $this->failInvalidCue('overlapping_timing', [
                    'cue_index' => $index,
                    'start_ms' => $draft['startMs'],
                    'previous_end_ms' => $previousEndMs,
                ]);
            }

            if ($enforceTextLength && $this->textLength($draft['sourceText']) > self::HARD_MAX_SOURCE_CHARS) {
                $this->failInvalidCue('source_text_too_long', [
                    'cue_index' => $index,
                    'source_character_count' => $this->textLength($draft['sourceText']),
                    'max_source_character_count' => self::HARD_MAX_SOURCE_CHARS,
                ]);
            }

            $previousEndMs = $draft['endMs'];
        }
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array{startMs: int, endMs: int, sourceText: string}  $draft
     */
    private function durationMs(array $draft): int
    {
        return $draft['endMs'] - $draft['startMs'];
    }

    private function textLength(string $text): int
    {
        return mb_strlen($text);
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
