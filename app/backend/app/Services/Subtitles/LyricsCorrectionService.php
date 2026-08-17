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
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

final class LyricsCorrectionService
{
    private const STAGES = ['aligning', 'tokenizing', 'analyzing', 'romanizing', 'enriching', 'finalizing'];

    public function __construct(
        private readonly BillingEntitlementService $billing,
        private readonly SubtitleJobArtifactStore $artifacts,
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

        return $normalizedLyrics;
    }

    public function submit(SubtitleJob $job, User $user, string $lyrics): SubtitleTrackLyricsCorrection
    {
        $trackId = $job->track?->getKey();
        $attemptId = (string) Str::uuid();

        $correction = DB::transaction(function () use ($job, $user, $lyrics, $trackId, $attemptId): SubtitleTrackLyricsCorrection {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($this->billing->activePlan($lockedUser) === null) {
                throw BillingEntitlementException::paymentRequired();
            }

            $track = SubtitleTrack::query()
                ->whereKey($trackId)
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
            $correction = $track->lyricsCorrection()->first();
            $initialState = ['stage' => 'aligning'];

            if ($correction instanceof SubtitleTrackLyricsCorrection) {
                $correction->update([
                    'attempt_id' => $attemptId,
                    'status' => 'queued',
                    'work_revision' => 0,
                    'work_state' => $initialState,
                    'lyrics' => $normalizedLyrics,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            } else {
                $correction = $track->lyricsCorrection()->create([
                    'attempt_id' => $attemptId,
                    'status' => 'queued',
                    'work_revision' => 0,
                    'work_state' => $initialState,
                    'lyrics' => $normalizedLyrics,
                ]);
            }

            return $correction;
        }, attempts: 5);

        try {
            LyricsCorrectionJob::dispatch($correction->subtitle_track_id, $job->getKey(), $attemptId, 0)
                ->onQueue(SubtitleQueue::batchNameForJob($job));
        } catch (Throwable $exception) {
            try {
                $this->failAttempt(
                    (int) $correction->subtitle_track_id,
                    $attemptId,
                    'lyrics_correction_failed',
                    'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.',
                    expectedRevision: 0,
                );
            } finally {
                throw $exception;
            }
        }

        return $correction->fresh(['track.job']);
    }

    public function process(int $trackId, string $attemptId, int $expectedRevision): void
    {
        $correction = $this->claimUnit($trackId, $attemptId, $expectedRevision);

        if ($correction === null) {
            return;
        }

        $job = $correction->track?->job;

        if (! $job instanceof SubtitleJob) {
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision);

            return;
        }

        $processedStage = is_array($correction->work_state) ? ($correction->work_state['stage'] ?? null) : null;

        try {
            $nextState = $this->advanceUnit($correction, $job);
        } catch (SubtitleProcessingException $exception) {
            if ($exception->isTransient()) {
                throw $exception;
            }

            $retryState = $this->narrowInvalidDerivedBatch($correction, $exception);

            if ($retryState !== null) {
                Log::info('backend.lyrics_correction_batch_retried', [
                    'track_id' => $trackId,
                    'subtitle_job_id' => $job->id,
                    'attempt_id' => $attemptId,
                    'stage' => $processedStage,
                    'batch_index' => $retryState['batchIndex'],
                    'reason' => $exception->context['reason'],
                ]);
                $this->commitProgress($trackId, $attemptId, $expectedRevision, $job, $processedStage, $retryState);

                return;
            }

            if ($exception->publicCode === 'lyrics_do_not_match') {
                $this->failAttempt($trackId, $attemptId, 'lyrics_do_not_match', 'These lyrics do not seem to match this song. Check the paste and try again.', expectedRevision: $expectedRevision);
            } else {
                $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision);
            }

            return;
        } catch (BillingEntitlementException $exception) {
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision);

            return;
        } catch (Throwable $exception) {
            Log::error('backend.lyrics_correction_failed', [
                'track_id' => $trackId,
                'subtitle_job_id' => $job->id,
                'attempt_id' => $attemptId,
                'stage' => 'processing',
                'exception' => $exception::class,
            ]);
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision);

            return;
        }

        $this->commitProgress($trackId, $attemptId, $expectedRevision, $job, $processedStage, $nextState);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function narrowInvalidDerivedBatch(
        SubtitleTrackLyricsCorrection $correction,
        SubtitleProcessingException $exception,
    ): ?array {
        $state = is_array($correction->work_state) ? $correction->work_state : [];
        $stage = $state['stage'] ?? null;
        $batchPlan = $state['batchPlan'] ?? null;
        $batchIndex = $state['batchIndex'] ?? null;

        if (! in_array($stage, ['tokenizing', 'analyzing', 'enriching'], true)
            || ! is_array($batchPlan)
            || ! is_int($batchIndex)
            || ! isset($batchPlan[$batchIndex])
            || ! is_array($batchPlan[$batchIndex])) {
            return null;
        }

        $bounds = $batchPlan[$batchIndex];
        $start = $bounds[0] ?? null;
        $end = $bounds[1] ?? null;

        if (! is_int($start) || ! is_int($end) || $end <= $start) {
            return null;
        }

        $cueCount = $end - $start + 1;
        $canRetry = $stage === 'enriching'
            ? $this->translationAnalysis->shouldRetryEnrichmentBatch($exception, $cueCount)
            : $this->translationAnalysis->shouldRetryTokenizationBatch($exception, $cueCount);

        if (! $canRetry) {
            return null;
        }

        $leftEnd = $start + intdiv($cueCount, 2) - 1;
        array_splice($batchPlan, $batchIndex, 1, [
            [$start, $leftEnd],
            [$leftEnd + 1, $end],
        ]);

        return [...$state, 'batchPlan' => $batchPlan];
    }

    public function failAttempt(
        int $trackId,
        string $attemptId,
        string $errorCode,
        string $message,
        int $expectedRevision,
        ?CarbonInterface $notUpdatedAfter = null,
    ): bool {
        $updated = SubtitleTrackLyricsCorrection::query()
            ->where('subtitle_track_id', $trackId)
            ->where('attempt_id', $attemptId)
            ->where('work_revision', $expectedRevision)
            ->whereIn('status', ['queued', 'running'])
            ->when($notUpdatedAfter !== null, fn ($query) => $query->where('updated_at', '<=', $notUpdatedAfter))
            ->update([
                'status' => 'failed',
                'work_state' => null,
                'lyrics' => null,
                'error_code' => $errorCode,
                'error_message' => $message,
            ]);

        return $updated === 1;
    }

    private function claimUnit(int $trackId, string $attemptId, int $expectedRevision): ?SubtitleTrackLyricsCorrection
    {
        return DB::transaction(function () use ($trackId, $attemptId, $expectedRevision): ?SubtitleTrackLyricsCorrection {
            $correction = SubtitleTrackLyricsCorrection::query()
                ->where('subtitle_track_id', $trackId)
                ->where('attempt_id', $attemptId)
                ->lockForUpdate()
                ->first();

            if (! $correction instanceof SubtitleTrackLyricsCorrection
                || ! in_array($correction->status, ['queued', 'running'], true)
                || $correction->work_revision !== $expectedRevision) {
                return null;
            }

            $stage = is_array($correction->work_state) ? ($correction->work_state['stage'] ?? null) : null;

            if (! is_string($stage) || ! in_array($stage, self::STAGES, true)) {
                return null;
            }

            if ($correction->status === 'queued') {
                $correction->update(['status' => 'running']);
            }

            return $correction->fresh(['track.job']);
        }, attempts: 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function advanceUnit(SubtitleTrackLyricsCorrection $correction, SubtitleJob $job): array
    {
        $lyrics = $correction->lyrics;

        if (! is_string($lyrics) || $lyrics === '') {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'missing_lyrics']);
        }

        $state = is_array($correction->work_state) ? $correction->work_state : [];
        $stage = $state['stage'] ?? null;

        return match ($stage) {
            'aligning' => $this->aligningUnit($correction, $job, $lyrics),
            'tokenizing', 'analyzing', 'romanizing', 'enriching' => $this->derivedUnit($job, $state, $stage),
            'finalizing' => $this->finalizingUnit($state),
            default => throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_stage']),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function aligningUnit(SubtitleTrackLyricsCorrection $correction, SubtitleJob $job, string $lyrics): array
    {
        $track = $correction->track;

        if (! $track instanceof SubtitleTrack) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'missing_track']);
        }

        $draftCues = $this->alignedCues($job, $track->cues, $lyrics, $job->effectiveSourceLanguage(), $correction->attempt_id);
        $batchPlan = $this->artifacts->batchPlan($draftCues);

        if ($batchPlan === []) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'empty_batch_plan']);
        }

        if ($this->translationRequested($job)) {
            return [
                'stage' => 'analyzing',
                'batchIndex' => 0,
                'batchPlan' => $batchPlan,
                'cues' => $draftCues,
            ];
        }

        return [
            'stage' => 'tokenizing',
            'batchIndex' => 0,
            'batchPlan' => $batchPlan,
            'cues' => $draftCues,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function derivedUnit(SubtitleJob $job, array $state, string $stage): array
    {
        $batchPlan = $state['batchPlan'] ?? null;
        $batchIndex = (int) ($state['batchIndex'] ?? -1);
        $cues = $state['cues'] ?? null;

        if (! is_array($batchPlan) || ! is_array($cues) || $batchPlan === [] || ! isset($batchPlan[$batchIndex])) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_batch_state']);
        }

        $bounds = $batchPlan[$batchIndex];
        $batch = array_slice($cues, $bounds[0], $bounds[1] - $bounds[0] + 1);

        if ($batch === []) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'empty_batch']);
        }

        $this->requireActivePlan($job);

        $cues = $this->processedBatch($cues, $batch, $bounds, $job, $stage);

        if (isset($batchPlan[$batchIndex + 1])) {
            return [...$state, 'batchIndex' => $batchIndex + 1, 'cues' => $cues];
        }

        return $this->nextStage([...$state, 'cues' => $cues], $job, $stage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array{0: int, 1: int}  $bounds
     * @return array<int, array<string, mixed>>
     */
    private function processedBatch(array $cues, array $batch, array $bounds, SubtitleJob $job, string $stage): array
    {
        switch ($stage) {
            case 'tokenizing':
                $result = $this->translationAnalysis->tokenizeCueBatch($batch, $cues, $job->effectiveSourceLanguage(), false);
                $this->costs->recordCueBatch($job, 'tokenizing', count($result->cues));
                $this->mergeIntoPositions($cues, $result->cues, $bounds);

                return $cues;

            case 'analyzing':
                $result = $this->translationAnalysis->analyzeCueBatch($batch, $cues, $job->effectiveSourceLanguage(), $job->target_language, false);
                $this->costs->recordAnalyzedCueBatch($job, count($result->tokenized->cues));
                $translatedByCueId = [];

                foreach ($result->translated->cues as $cue) {
                    $translatedByCueId[(string) $cue['cueId']] = (string) $cue['translatedText'];
                }

                $merged = [];

                foreach ($result->tokenized->cues as $cue) {
                    $merged[] = [
                        ...$cue,
                        'translatedText' => $translatedByCueId[(string) $cue['cueId']] ?? (string) $cue['sourceText'],
                    ];
                }

                $this->mergeIntoPositions($cues, $merged, $bounds);

                return $cues;

            case 'romanizing':
                $result = $this->translationAnalysis->romanizeCueBatch($batch, $job->effectiveSourceLanguage());
                $this->costs->recordCueBatch($job, 'romanizing', count($result->cues));
                $this->mergeIntoPositions($cues, $result->cues, $bounds);

                return $cues;

            case 'enriching':
                $result = $this->translationAnalysis->enrichCueBatch($batch, $job->effectiveSourceLanguage(), $job->target_language, $job->include_romanization, false);
                $this->costs->recordCueBatch($job, 'enriching', count($result->cues));
                $this->mergeIntoPositions($cues, $result->cues, $bounds);

                return $cues;

            default:
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_stage']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     * @param  array<int, array<string, mixed>>  $processed
     * @param  array{0: int, 1: int}  $bounds
     */
    private function mergeIntoPositions(array &$cues, array $processed, array $bounds): void
    {
        foreach (array_values($processed) as $offset => $cue) {
            $cues[$bounds[0] + $offset] = $cue;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function nextStage(array $state, SubtitleJob $job, string $stage): array
    {
        if (($stage === 'tokenizing' || $stage === 'analyzing') && $job->include_romanization && $this->containsNonLatin($state['cues'])) {
            return [...$state, 'stage' => 'romanizing', 'batchIndex' => 0];
        }

        if (($stage === 'tokenizing' || $stage === 'analyzing' || $stage === 'romanizing') && $this->fullEnrichmentRequested($job)) {
            return [...$state, 'stage' => 'enriching', 'batchIndex' => 0];
        }

        return ['stage' => 'finalizing', 'cues' => $state['cues']];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function finalizingUnit(array $state): array
    {
        $cues = $state['cues'] ?? null;

        if (! is_array($cues) || $cues === []) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'missing_assembled_cues']);
        }

        $cues = array_values(array_map(
            fn (array $cue, int $index): array => [
                ...$cue,
                'index' => $index,
                'startMs' => (int) $cue['startMs'],
                'endMs' => (int) $cue['endMs'],
            ],
            $cues,
            array_keys($cues),
        ));

        return [
            'stage' => 'completed',
            'cues' => $cues,
            'webVtt' => $this->webVtt($cues),
        ];
    }

    /**
     * @param  array<string, mixed>  $nextState
     */
    private function commitProgress(int $trackId, string $attemptId, int $expectedRevision, SubtitleJob $job, ?string $processedStage, array $nextState): void
    {
        $nextRevision = DB::transaction(function () use ($trackId, $attemptId, $expectedRevision, $processedStage, $nextState): ?int {
            $locked = SubtitleTrackLyricsCorrection::query()
                ->where('subtitle_track_id', $trackId)
                ->where('attempt_id', $attemptId)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SubtitleTrackLyricsCorrection
                || $locked->status !== 'running'
                || $locked->work_revision !== $expectedRevision
                || ($locked->work_state['stage'] ?? null) !== $processedStage) {
                return null;
            }

            if (($nextState['stage'] ?? null) === 'completed') {
                $track = SubtitleTrack::query()->whereKey($trackId)->lockForUpdate()->first();

                if (! $track instanceof SubtitleTrack) {
                    return null;
                }

                $track->update([
                    'public_id' => (string) Str::uuid(),
                    'generated_at' => now(),
                    'cues' => $nextState['cues'],
                    'web_vtt' => $nextState['webVtt'],
                ]);
                $locked->update([
                    'status' => 'completed',
                    'work_revision' => $expectedRevision + 1,
                    'work_state' => null,
                    'lyrics' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);

                return null;
            }

            $nextRevision = $expectedRevision + 1;
            $locked->update([
                'work_revision' => $nextRevision,
                'work_state' => $nextState,
            ]);

            return $nextRevision;
        }, attempts: 5);

        if ($nextRevision === null) {
            return;
        }

        try {
            LyricsCorrectionJob::dispatch($trackId, $job->getKey(), $attemptId, $nextRevision)
                ->onQueue(SubtitleQueue::batchNameForJob($job));
        } catch (Throwable $exception) {
            try {
                $this->failAttempt(
                    $trackId,
                    $attemptId,
                    'lyrics_correction_failed',
                    'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.',
                    expectedRevision: $nextRevision,
                );
            } finally {
                throw $exception;
            }
        }
    }

    private function alignedCues(SubtitleJob $job, array $sourceCues, string $lyrics, string $sourceLanguage, string $attemptId): array
    {
        $matchingLyrics = $this->matchingLyrics($lyrics);
        $input = [
            'sourceLanguage' => $sourceLanguage,
            'cues' => array_map(
                fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'startMs', 'endMs', 'sourceText']),
                array_values($sourceCues),
            ),
            'pastedLyrics' => $lyrics,
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->requireActivePlan($job);
            $output = $this->promptAlignment($input);
            $this->costs->recordCorrectionAlignment($job);

            try {
                return $this->validatedAlignment($sourceCues, $matchingLyrics, $output, $attemptId);
            } catch (SubtitleProcessingException $exception) {
                if ($exception->isTransient() || $exception->publicCode === 'lyrics_do_not_match') {
                    throw $exception;
                }
            }
        }

        throw SubtitleProcessingException::lyricsCorrectionFailed();
    }

    private function promptAlignment(array $input): array
    {
        try {
            return LyricsAlignmentAgent::make()
                ->prompt(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
                ->toArray();
        } catch (RateLimitedException $exception) {
            throw SubtitleProcessingException::rateLimited(
                'Subtitle AI processing is temporarily rate limited.',
                [
                    'provider' => Lab::OpenAI->value,
                    'adapter' => 'laravel-ai-sdk',
                    'agent' => LyricsAlignmentAgent::class,
                    'exception' => $exception::class,
                ],
                $exception,
            );
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $context = [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'agent' => LyricsAlignmentAgent::class,
                'exception' => $exception::class,
            ];

            if ($exception instanceof ConnectionException) {
                throw SubtitleProcessingException::providerUnavailable(
                    'Subtitle AI provider did not respond.',
                    [...$context, 'reason' => 'connection_failure'],
                    $exception,
                );
            }

            if ($exception instanceof RequestException) {
                $context['status'] = $exception->response->status();

                if ($exception->response->status() === 429) {
                    throw SubtitleProcessingException::rateLimited(
                        'Subtitle AI processing is temporarily rate limited.',
                        $context,
                        $exception,
                    );
                }

                if ($exception->response->serverError()) {
                    throw SubtitleProcessingException::providerUnavailable(
                        'Subtitle AI provider is temporarily unavailable.',
                        $context,
                        $exception,
                    );
                }
            }

            throw SubtitleProcessingException::enrichmentFailed(
                'Subtitle AI processing failed.',
                $context,
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

        if (! is_array($outputCues) || $outputCues === [] || count($outputCues) > count($sourceCues)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_count_mismatch']);
        }

        $sourceCuesById = [];

        foreach (array_values($sourceCues) as $position => $sourceCue) {
            $sourceCuesById[$sourceCue['cueId']] = ['position' => $position, 'cue' => $sourceCue];
        }

        $cursor = 0;
        $draft = [];
        $previousSourcePosition = -1;

        foreach (array_values($outputCues) as $position => $outputCue) {
            $source = is_array($outputCue) ? ($sourceCuesById[$outputCue['cueId'] ?? ''] ?? null) : null;
            $sourceCue = is_array($source) ? ($source['cue'] ?? null) : null;
            $sourcePosition = is_array($source) ? ($source['position'] ?? null) : null;
            $text = is_array($outputCue) ? $outputCue['sourceText'] ?? null : null;

            if (! is_array($sourceCue)
                || ! is_int($sourcePosition)
                || $sourcePosition <= $previousSourcePosition
                || ($outputCue['index'] ?? null) !== ($sourceCue['index'] ?? null)
                || ! is_string($text)) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_identity_mismatch']);
            }

            $previousSourcePosition = $sourcePosition;

            $text = SubtitleText::collapseWhitespace($text);
            $length = mb_strlen($text, 'UTF-8');

            if ($text === '' || $length > 84) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_cue_text']);
            }

            $foundAt = mb_strpos($lyrics, $text, $cursor, 'UTF-8');

            if ($foundAt === false) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'source_text_not_in_paste']);
            }

            if (SubtitleText::collapseWhitespace(mb_substr($lyrics, $cursor, $foundAt - $cursor, 'UTF-8')) !== '') {
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

        if (SubtitleText::collapseWhitespace(mb_substr($lyrics, $cursor, null, 'UTF-8')) !== '') {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'unmatched_pasted_text']);
        }

        return $draft;
    }

    private function matchingLyrics(string $normalizedLyrics): string
    {
        $lines = preg_split('/\n/u', $normalizedLyrics) ?: [];
        $lyrics = array_values(array_filter($lines, fn (string $line): bool => ! $this->isSkippableLabelLine($line)));

        if ($lyrics === []) {
            throw new SubtitleProcessingException('validation_failed', 'Lyrics must contain sung text.', 422);
        }

        return implode(' ', $lyrics);
    }

    private function isSkippableLabelLine(string $line): bool
    {
        return preg_match('/^\[(?:intro|outro|verse(?:\s+\d+)?|chorus(?:\s+\d+)?|bridge(?:\s+\d+)?|pre[- ]chorus(?:\s+\d+)?|interlude)\]$/iu', $line) === 1
            || preg_match('/^(?:intro|outro|verse(?:\s+\d+)?|chorus(?:\s+\d+)?|bridge(?:\s+\d+)?|pre[- ]chorus(?:\s+\d+)?|interlude)$/iu', $line) === 1
            || preg_match('/^(?:written by|music by|lyrics by)\s+[\p{L}\p{N}][\p{L}\p{N}\s.,&\'-]{0,78}$/iu', $line) === 1;
    }

    private function translationRequested(SubtitleJob $job): bool
    {
        return $job->include_translation && $job->effectiveSourceLanguage() !== $job->target_language;
    }

    private function fullEnrichmentRequested(SubtitleJob $job): bool
    {
        return $job->enrichment_mode === 'full' && $job->effectiveSourceLanguage() !== $job->target_language;
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

    private function webVtt(array $cues): string
    {
        $previousEnd = null;

        foreach ($cues as $cue) {
            $start = (int) $cue['startMs'];
            $end = (int) $cue['endMs'];

            if ($start < 0 || $end <= $start || ($previousEnd !== null && $start < $previousEnd) || (string) $cue['sourceText'] === '' || mb_strlen((string) $cue['sourceText'], 'UTF-8') > 84) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_timing_or_text']);
            }

            $previousEnd = $end;
        }

        return SubtitleWebVttFormatter::fromCues($cues);
    }

    private function requireActivePlan(SubtitleJob $job): void
    {
        $user = User::query()->find($job->user_id);

        if (! $user instanceof User || $this->billing->activePlan($user) === null) {
            throw BillingEntitlementException::paymentRequired();
        }
    }
}
