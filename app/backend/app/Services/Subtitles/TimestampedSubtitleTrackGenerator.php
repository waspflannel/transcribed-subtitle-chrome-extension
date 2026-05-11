<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Support\Str;

class TimestampedSubtitleTrackGenerator
{
    public function generate(SubtitleJob $job, TimestampedTranscript $transcript, CueEnrichmentResult $enrichment): SubtitleTrack
    {
        $generatedAt = now();

        return SubtitleTrack::create([
            'public_id' => (string) Str::uuid(),
            'subtitle_job_id' => $job->id,
            'youtube_video_id' => $job->youtube_video_id,
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'source_dialect' => $enrichment->sourceDialect,
            'processing_version' => $job->processing_version,
            'generated_at' => $generatedAt,
            'expires_at' => $generatedAt->copy()->addDays(30),
            'web_vtt' => $this->validatedWebVtt($transcript),
            'cues' => $this->validatedEnrichedCues($enrichment->cues),
        ]);
    }

    /**
     * @return array<int, array{cueId: string, index: int, startMs: int, endMs: int, sourceText: string, translatedText: string, tokens: array<int, mixed>}>
     */
    public function draftCues(TimestampedTranscript $transcript): array
    {
        $previousEndMs = null;
        $cues = [];

        foreach ($transcript->segments as $index => $segment) {
            $sourceText = $this->normalizeText($segment->text);
            $startMs = (int) round($segment->startSeconds * 1000);
            $endMs = (int) round($segment->endSeconds * 1000);

            $this->validateCue($sourceText, $startMs, $endMs, $index, $previousEndMs);
            $previousEndMs = $endMs;

            $cues[] = [
                'cueId' => sprintf('cue-%04d', $index + 1),
                'index' => $index,
                'startMs' => $startMs,
                'endMs' => $endMs,
                'sourceText' => $sourceText,
                'translatedText' => '',
                'tokens' => [],
            ];
        }

        return $cues;
    }

    /**
     * @param  array<int, array<string, mixed>>  $draftCues
     */
    public function transcriptOnlyEnrichment(array $draftCues): CueEnrichmentResult
    {
        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'tokens' => $this->tokenStubs((string) $cue['sourceText']),
                ],
                $draftCues,
            ),
            'unknown',
        );
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

    /**
     * @param  array<int, array<string, mixed>>  $cues
     * @return array<int, array<string, mixed>>
     */
    private function validatedEnrichedCues(array $cues): array
    {
        if ($cues === []) {
            $this->failInvalidEnrichedCue('empty_enriched_cues');
        }

        foreach ($cues as $position => $cue) {
            if (! is_array($cue)) {
                $this->failInvalidEnrichedCue('invalid_enriched_cue', ['cue_position' => $position]);
            }

            foreach (['cueId', 'sourceText', 'translatedText'] as $field) {
                if (! is_string($cue[$field] ?? null) || trim($cue[$field]) === '') {
                    $this->failInvalidEnrichedCue('invalid_enriched_cue', [
                        'cue_position' => $position,
                        'field' => $field,
                    ]);
                }
            }

            foreach (['index', 'startMs', 'endMs'] as $field) {
                if (! is_int($cue[$field] ?? null)) {
                    $this->failInvalidEnrichedCue('invalid_enriched_cue', [
                        'cue_position' => $position,
                        'field' => $field,
                    ]);
                }
            }

            if (! is_array($cue['tokens'] ?? null)) {
                $this->failInvalidEnrichedCue('invalid_enriched_cue', [
                    'cue_position' => $position,
                    'field' => 'tokens',
                ]);
            }
        }

        return array_values($cues);
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
     * @return array<int, array{index: int, text: string, normalizedText: string}>
     */
    private function tokenStubs(string $sourceText): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}\']+/u', $sourceText, $matches);

        $words = array_values(array_filter(
            $matches[0] ?? [],
            fn (string $word): bool => trim($word) !== '',
        ));

        if ($words === []) {
            $words = [$sourceText];
        }

        return array_map(
            fn (string $word, int $index): array => [
                'index' => $index,
                'text' => $word,
                'normalizedText' => $this->normalizeTokenText($word),
            ],
            $words,
            array_keys($words),
        );
    }

    private function normalizeTokenText(string $text): string
    {
        $normalized = $this->normalizeText($text);

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
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

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws SubtitleProcessingException
     */
    private function failInvalidEnrichedCue(string $reason, array $context = []): never
    {
        throw SubtitleProcessingException::enrichmentFailed(
            'Subtitle enrichment produced invalid output.',
            [
                'reason' => $reason,
                ...$context,
            ],
        );
    }
}
