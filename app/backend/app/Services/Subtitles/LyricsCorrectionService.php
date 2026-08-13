<?php

namespace App\Services\Subtitles;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Exceptions\BillingEntitlementException;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\LyricsCorrectionJob;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Text\SubtitleText;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

final class LyricsCorrectionService
{
    public const MAX_LYRICS_CHARACTERS = 25000;

    public function __construct(
        private readonly BillingEntitlementService $billing,
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly SubtitleProviderCostRecorder $costs,
    ) {}

    public function normalizeLyrics(string $lyrics): string
    {
        $lines = preg_split('/\R/u', $lyrics) ?: [];
        $normalized = [];

        foreach ($lines as $line) {
            $line = SubtitleText::collapseWhitespace($line);

            if ($line !== '') {
                $normalized[] = $line;
            }
        }

        $normalizedLyrics = implode("\n", $normalized);

        if ($normalizedLyrics === '' || preg_match('/[\p{L}\p{N}]/u', $normalizedLyrics) !== 1) {
            throw new SubtitleProcessingException('validation_failed', 'Lyrics must contain at least one letter or number.', 422);
        }

        if (mb_strlen($normalizedLyrics, 'UTF-8') > self::MAX_LYRICS_CHARACTERS) {
            throw new SubtitleProcessingException('validation_failed', 'Lyrics may not exceed 25,000 characters.', 422);
        }

        return $normalizedLyrics;
    }

    public function submit(SubtitleJob $job, User $user, string $lyrics): SubtitleTrackLyricsCorrection
    {
        return DB::transaction(function () use ($job, $user, $lyrics): SubtitleTrackLyricsCorrection {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->billing->activePlan($lockedUser) === null) {
                throw BillingEntitlementException::paymentRequired();
            }

            $track = SubtitleTrack::query()
                ->whereKey($job->track?->getKey())
                ->whereBelongsTo($job, 'job')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $track instanceof SubtitleTrack || $job->status !== 'completed') {
                abort(404);
            }

            $inProgress = SubtitleTrackLyricsCorrection::query()
                ->whereIn('status', ['queued', 'running'])
                ->whereHas('track.job', fn ($query) => $query->whereBelongsTo($lockedUser))
                ->exists();

            if ($inProgress) {
                throw SubtitleProcessingException::lyricsCorrectionInProgress();
            }

            $normalizedLyrics = $this->normalizeLyrics($lyrics);
            $attemptId = (string) Str::uuid();
            $correction = $track->lyricsCorrection()->first();

            if ($correction instanceof SubtitleTrackLyricsCorrection) {
                $correction->update([
                    'attempt_id' => $attemptId,
                    'status' => 'queued',
                    'lyrics' => $normalizedLyrics,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            } else {
                $correction = $track->lyricsCorrection()->create([
                    'attempt_id' => $attemptId,
                    'status' => 'queued',
                    'lyrics' => $normalizedLyrics,
                ]);
            }

            LyricsCorrectionJob::dispatch($track->getKey(), $job->getKey(), $attemptId)
                ->onConnection(SubtitleQueue::connection())
                ->onQueue(SubtitleQueue::batchNameForJob($job))
                ->afterCommit();

            return $correction->fresh(['track.job']);
        }, attempts: 5);
    }

    public function process(int $trackId, string $attemptId): void
    {
        $correction = DB::transaction(function () use ($trackId, $attemptId): ?SubtitleTrackLyricsCorrection {
            $correction = SubtitleTrackLyricsCorrection::query()
                ->where('subtitle_track_id', $trackId)
                ->where('attempt_id', $attemptId)
                ->lockForUpdate()
                ->first();

            if (! $correction instanceof SubtitleTrackLyricsCorrection || $correction->status !== 'queued') {
                return null;
            }

            $correction->update(['status' => 'running']);

            return $correction->fresh(['track.job']);
        });

        if (! $correction instanceof SubtitleTrackLyricsCorrection) {
            $this->clearLyrics($trackId, $attemptId);

            return;
        }

        $track = $correction->track;
        $job = $track?->job;
        $lyrics = $correction->lyrics;

        if (! $track instanceof SubtitleTrack || ! $job instanceof SubtitleJob || ! is_string($lyrics) || $lyrics === '') {
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.');

            return;
        }

        try {
            $draftCues = $this->alignedCues($track->cues, $lyrics, $job->effectiveSourceLanguage(), $attemptId);
            $corrected = $this->rebuildDerivedCues($draftCues, $job);
            $webVtt = $this->webVtt($corrected);

            DB::transaction(function () use ($trackId, $attemptId, $corrected, $webVtt): void {
                $lockedCorrection = SubtitleTrackLyricsCorrection::query()
                    ->where('subtitle_track_id', $trackId)
                    ->where('attempt_id', $attemptId)
                    ->lockForUpdate()
                    ->first();
                $lockedTrack = SubtitleTrack::query()->whereKey($trackId)->lockForUpdate()->first();

                if (! $lockedCorrection instanceof SubtitleTrackLyricsCorrection || $lockedCorrection->status !== 'running' || ! $lockedTrack instanceof SubtitleTrack) {
                    return;
                }

                $lockedTrack->update([
                    'public_id' => (string) Str::uuid(),
                    'generated_at' => now(),
                    'cues' => $corrected,
                    'web_vtt' => $webVtt,
                ]);
                $lockedCorrection->update([
                    'status' => 'completed',
                    'lyrics' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }, attempts: 5);
        } catch (SubtitleProcessingException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            $this->failAttempt($trackId, $attemptId, $exception->publicCode === 'lyrics_do_not_match' ? 'lyrics_do_not_match' : 'lyrics_correction_failed', $exception->publicCode === 'lyrics_do_not_match' ? 'These lyrics do not seem to match this song. Check the paste and try again.' : 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.');
        } catch (Throwable $exception) {
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.');
        }
    }

    public function failAttempt(int $trackId, string $attemptId, string $errorCode, string $message): void
    {
        SubtitleTrackLyricsCorrection::query()
            ->where('subtitle_track_id', $trackId)
            ->where('attempt_id', $attemptId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'lyrics' => null,
                'error_code' => $errorCode,
                'error_message' => $message,
            ]);
    }

    public function clearLyrics(int $trackId, string $attemptId): void
    {
        SubtitleTrackLyricsCorrection::query()
            ->where('subtitle_track_id', $trackId)
            ->where('attempt_id', $attemptId)
            ->update(['lyrics' => null]);
    }

    private function alignedCues(array $sourceCues, string $lyrics, string $sourceLanguage, string $attemptId): array
    {
        $normalizedLyrics = $this->normalizeLyrics($lyrics);
        $matchingLyrics = str_replace("\n", ' ', $normalizedLyrics);
        $input = [
            'sourceLanguage' => $sourceLanguage,
            'cues' => array_map(
                fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'startMs', 'endMs', 'sourceText']),
                array_values($sourceCues),
            ),
            'pastedLyrics' => $normalizedLyrics,
        ];

        $lastOutput = null;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $output = $this->promptAlignment($input);

                return $this->validatedAlignment($sourceCues, $matchingLyrics, $output, $attemptId);
            } catch (SubtitleProcessingException $exception) {
                $lastOutput = $exception;

                if ($exception->isTransient()) {
                    throw $exception;
                }
            }
        }

        if ($lastOutput instanceof SubtitleProcessingException && $lastOutput->publicCode === 'lyrics_do_not_match') {
            throw $lastOutput;
        }

        throw SubtitleProcessingException::lyricsCorrectionFailed();
    }

    private function promptAlignment(array $input): array
    {
        try {
            return LyricsAlignmentAgent::make()
                ->prompt(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
                ->toArray();
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::enrichmentFailed(
                'Subtitle AI processing failed.',
                ['provider' => Lab::OpenAI->value, 'adapter' => 'laravel-ai-sdk', 'agent' => LyricsAlignmentAgent::class, 'exception' => $exception::class],
                $exception,
            );
        }
    }

    private function validatedAlignment(array $sourceCues, string $lyrics, array $output, string $attemptId): array
    {
        if (($output['isMatch'] ?? false) !== true) {
            throw SubtitleProcessingException::lyricsDoNotMatch();
        }

        $outputCues = $output['cues'] ?? null;

        if (! is_array($outputCues) || count($outputCues) !== count($sourceCues)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_count_mismatch']);
        }

        $cursor = 0;
        $draft = [];

        foreach (array_values($sourceCues) as $position => $sourceCue) {
            $outputCue = $outputCues[$position] ?? null;
            $text = is_array($outputCue) ? $outputCue['sourceText'] ?? null : null;

            if (! is_array($outputCue) || ($outputCue['cueId'] ?? null) !== ($sourceCue['cueId'] ?? null) || ($outputCue['index'] ?? null) !== ($sourceCue['index'] ?? null) || ! is_string($text)) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_identity_mismatch']);
            }

            $text = SubtitleText::collapseWhitespace($text);
            $length = mb_strlen($text, 'UTF-8');

            if ($text === '' || $length > 84) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_cue_text']);
            }

            $foundAt = mb_strpos($lyrics, $text, $cursor, 'UTF-8');

            if ($foundAt === false) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'source_text_not_in_paste']);
            }

            if (! $this->isSkippableLabel(mb_substr($lyrics, $cursor, $foundAt - $cursor, 'UTF-8'))) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'source_text_gap']);
            }

            $cursor = $foundAt + $length;
            $draft[] = [
                'cueId' => sprintf('lyrics-%s-%04d', substr(str_replace('-', '', $attemptId), 0, 8), $position + 1),
                'index' => $position,
                'startMs' => (int) $sourceCue['startMs'],
                'endMs' => (int) $sourceCue['endMs'],
                'sourceText' => $text,
                'translatedText' => $text,
                'tokens' => [],
            ];
        }

        if (! $this->isSkippableLabel(mb_substr($lyrics, $cursor, null, 'UTF-8'))) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'unmatched_pasted_text']);
        }

        return $draft;
    }

    private function isSkippableLabel(string $gap): bool
    {
        $gap = SubtitleText::collapseWhitespace($gap);

        if ($gap === '') {
            return true;
        }

        return preg_match('/^(?:\[[^\]]{1,80}\]|(?:intro|outro|verse|chorus|bridge|pre[- ]chorus|interlude|written by|music by|lyrics by)\b[^\[]*)$/iu', $gap) === 1;
    }

    private function rebuildDerivedCues(array $draftCues, SubtitleJob $job): array
    {
        $tokenized = [];
        $translated = [];

        foreach ($this->batches($draftCues) as $batch) {
            if ($this->translationRequested($job)) {
                $result = $this->translationAnalysis->analyzeCueBatch($batch, $draftCues, $job->effectiveSourceLanguage(), $job->target_language);
                $this->costs->recordAnalyzedCueBatch($job, count($result->tokenized->cues));
                $tokenized = [...$tokenized, ...$result->tokenized->cues];
                $translated = [...$translated, ...$result->translated->cues];
            } else {
                $result = $this->translationAnalysis->tokenizeCueBatch($batch, $draftCues, $job->effectiveSourceLanguage());
                $this->costs->recordCueBatch($job, 'tokenizing', count($result->cues));
                $tokenized = [...$tokenized, ...$result->cues];
            }
        }

        $translatedById = [];
        foreach ($translated as $cue) {
            $translatedById[(string) $cue['cueId']] = (string) $cue['translatedText'];
        }

        $merged = array_map(function (array $cue) use ($translatedById): array {
            return [
                ...$cue,
                'translatedText' => $translatedById[(string) $cue['cueId']] ?? (string) $cue['sourceText'],
            ];
        }, $tokenized);

        if ($job->include_romanization && $this->containsNonLatin($merged)) {
            $romanized = [];

            foreach ($this->batches($merged) as $batch) {
                $romanized = [...$romanized, ...$this->translationAnalysis->romanizeCueBatch($batch, $job->effectiveSourceLanguage())->cues];
                $this->costs->recordCueBatch($job, 'romanizing', count($batch));
            }

            $romanizedById = [];
            foreach ($romanized as $cue) {
                $romanizedById[(string) $cue['cueId']] = $cue;
            }

            $merged = array_map(function (array $cue) use ($romanizedById): array {
                $annotation = $romanizedById[(string) $cue['cueId']] ?? [];

                return [
                    ...$cue,
                    ...Arr::only($annotation, ['romanization', 'tokens']),
                ];
            }, $merged);
        }

        if ($job->enrichment_mode === 'full' && $job->effectiveSourceLanguage() !== $job->target_language) {
            $enriched = [];

            foreach ($this->batches($merged) as $batch) {
                $enriched = [...$enriched, ...$this->translationAnalysis->enrichCueBatch($batch, $job->effectiveSourceLanguage(), $job->target_language, $job->include_romanization)->cues];
                $this->costs->recordCueBatch($job, 'enriching', count($batch));
            }

            $merged = $enriched;
        }

        return array_values(array_map(fn (array $cue, int $index): array => [
            ...$cue,
            'index' => $index,
            'startMs' => (int) $cue['startMs'],
            'endMs' => (int) $cue['endMs'],
        ], $merged, array_keys($merged)));
    }

    private function translationRequested(SubtitleJob $job): bool
    {
        return $job->include_translation && $job->effectiveSourceLanguage() !== $job->target_language;
    }

    private function containsNonLatin(array $cues): bool
    {
        foreach ($cues as $cue) {
            if (preg_match('/(?!\p{Latin})\p{L}/u', (string) $cue['sourceText']) === 1) {
                return true;
            }
        }

        return false;
    }

    private function batches(array $cues): array
    {
        $batches = [];
        $batch = [];
        $characters = 0;
        $budget = max(1, (int) config('subtitles.enrichment.cue_batch_char_budget', 1000));
        $maxCues = max(1, (int) config('subtitles.enrichment.cue_batch_max_cues', 20));

        foreach ($cues as $cue) {
            $length = mb_strlen((string) $cue['sourceText'], 'UTF-8');

            if ($batch !== [] && ($characters + $length > $budget || count($batch) >= $maxCues)) {
                $batches[] = $batch;
                $batch = [];
                $characters = 0;
            }

            $batch[] = $cue;
            $characters += $length;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    private function webVtt(array $cues): string
    {
        $blocks = ['WEBVTT'];
        $previousEnd = null;

        foreach ($cues as $cue) {
            $start = (int) $cue['startMs'];
            $end = (int) $cue['endMs'];

            if ($start < 0 || $end <= $start || ($previousEnd !== null && $start < $previousEnd) || (string) $cue['sourceText'] === '' || mb_strlen((string) $cue['sourceText'], 'UTF-8') > 84) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_timing_or_text']);
            }

            $blocks[] = implode("\n", [
                (string) $cue['cueId'],
                $this->timestamp($start).' --> '.$this->timestamp($end),
                (string) $cue['sourceText'],
            ]);
            $previousEnd = $end;
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function timestamp(int $milliseconds): string
    {
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds %= 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds %= 60_000;
        $seconds = intdiv($milliseconds, 1000);
        $milliseconds %= 1000;

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $milliseconds);
    }
}
