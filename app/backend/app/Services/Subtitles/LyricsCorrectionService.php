<?php

namespace App\Services\Subtitles;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Ai\SubtitleModel;
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
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

final class LyricsCorrectionService
{
    private const STAGES = ['aligning', 'analyzing', 'finalizing'];

    // Generous allocation limits catch collapsed songs without rejecting fast lyrics or short interjections.
    private const MIN_SLOT_CHARACTERS = 12;

    private const MAX_SLOT_CHARACTERS_PER_SECOND = 60;

    private const MAX_SLOT_TEXT_EXPANSION = 3;

    public function __construct(
        private readonly BillingEntitlementService $billing,
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly SubtitleProviderCostRecorder $costs,
        private readonly LearningTokenOutputValidator $tokenValidator,
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

    public function submit(SubtitleJob $job, User $user, string $lyrics, string $expectedTrackId, bool $allowPartial = false): SubtitleTrackLyricsCorrection
    {
        $trackId = $job->track?->getKey();
        $attemptId = (string) Str::uuid();

        $correction = DB::transaction(function () use ($job, $user, $lyrics, $trackId, $attemptId, $expectedTrackId, $allowPartial): SubtitleTrackLyricsCorrection {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $job = SubtitleJob::query()->whereKey($job->id)->whereBelongsTo($lockedUser)->lockForUpdate()->firstOrFail();

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

            if (! hash_equals((string) $track->public_id, $expectedTrackId)) {
                throw SubtitleProcessingException::lyricsTrackChanged();
            }

            $inProgress = SubtitleTrackLyricsCorrection::query()
                ->whereIn('status', ['queued', 'running'])
                ->whereHas('track.job', fn ($query) => $query->whereBelongsTo($lockedUser))
                ->exists();

            if ($inProgress) {
                throw SubtitleProcessingException::lyricsCorrectionInProgress();
            }

            $normalizedLyrics = $this->sungLyrics($this->normalizeLyrics($lyrics));
            $characterCount = mb_strlen(preg_replace('/\s/u', '', $normalizedLyrics), 'UTF-8');
            if ($characterCount > count($track->cues) * 84) {
                throw ValidationException::withMessages([
                    'lyrics' => ['These lyrics cannot fit the existing timing slots. Remove non-lyric text or generate a new track.'],
                ]);
            }
            $correction = $track->lyricsCorrection()->first();
            $initialState = [
                'stage' => 'aligning',
                'trackId' => $track->public_id,
                'runId' => $job->run_id,
                'allowPartial' => $allowPartial,
            ];

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

    public function cancel(SubtitleJob $job, User $user, string $attemptId): SubtitleTrackLyricsCorrection
    {
        if ((int) $job->user_id !== (int) $user->id) {
            abort(404);
        }

        return DB::transaction(function () use ($job, $attemptId): SubtitleTrackLyricsCorrection {
            $track = SubtitleTrack::query()
                ->where('subtitle_job_id', $job->getKey())
                ->where('expires_at', '>', now())
                ->whereHas('job', fn ($query) => $query->where('status', 'completed'))
                ->lockForUpdate()
                ->firstOrFail();
            $correction = SubtitleTrackLyricsCorrection::query()
                ->where('subtitle_track_id', $track->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals((string) $correction->attempt_id, $attemptId)) {
                throw SubtitleProcessingException::lyricsCorrectionInProgress();
            }

            if (in_array($correction->status, ['queued', 'running'], true)) {
                $correction->update([
                    'status' => 'cancelled',
                    'work_revision' => $correction->work_revision + 1,
                    'lyrics' => null,
                    'work_state' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }

            return $correction->fresh(['track.job']);
        }, attempts: 5);
    }

    /**
     * @param  array{expectedTrackId: string, text: string}  $payload
     */
    public function quickFix(
        SubtitleJob $job,
        User $user,
        string $cueId,
        int $tokenIndex,
        array $payload,
    ): SubtitleTrack {
        if ((int) $job->user_id !== (int) $user->id) {
            abort(404);
        }

        [$cuePosition, $updatedCue] = DB::transaction(function () use ($job, $cueId, $tokenIndex, $payload): array {
            $track = $this->lockQuickFixTrack($job, $payload['expectedTrackId']);

            $cues = array_values($track->cues);
            $cuePosition = array_search($cueId, array_column($cues, 'cueId'), true);

            if (! is_int($cuePosition)) {
                abort(404);
            }

            $cue = $cues[$cuePosition];
            $tokens = is_array($cue['tokens'] ?? null) ? array_values($cue['tokens']) : [];
            if (! in_array($tokenIndex, array_column($tokens, 'index'), true)) {
                abort(404);
            }

            $replacement = SubtitleText::collapseWhitespace($payload['text']);
            $sourceText = (string) $cue['sourceText'];
            $span = $this->tokenSpan($sourceText, $tokens, $tokenIndex);
            $updatedTokens = [];
            $replacementNormalizedText = $this->tokenValidator->normalizeTokenText($replacement);

            foreach ($tokens as $token) {
                if (! is_array($token) || ! is_int($token['index'] ?? null) || ! is_string($token['text'] ?? null)) {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_quick_fix_tokens']);
                }

                if ($token['index'] === $tokenIndex && $replacementNormalizedText === (string) ($token['normalizedText'] ?? '')) {
                    throw new SubtitleProcessingException('validation_failed', 'Replacement text must change the selected token.', 422);
                }

                $normalizedText = $token['index'] === $tokenIndex
                    ? $replacementNormalizedText
                    : (string) ($token['normalizedText'] ?? '');

                if ($normalizedText === '') {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_quick_fix_tokens']);
                }

                $updatedTokens[] = [
                    'index' => $token['index'],
                    'text' => $token['index'] === $tokenIndex ? $replacement : $token['text'],
                    'normalizedText' => $normalizedText,
                ];
            }

            // Corrected model tokens may no longer align with the transcript.
            $updatedSourceText = $span === null
                ? SubtitleText::canonicalComparable(implode(' ', array_column($updatedTokens, 'text')))
                : mb_substr($sourceText, 0, $span[0], 'UTF-8')
                    .$replacement
                    .mb_substr($sourceText, $span[1], null, 'UTF-8');

            if (SubtitleText::collapseWhitespace($updatedSourceText) === '') {
                throw new SubtitleProcessingException('validation_failed', 'Replacement text must leave a non-empty subtitle line.', 422);
            }

            if (mb_strlen($updatedSourceText, 'UTF-8') > 84) {
                throw ValidationException::withMessages([
                    'text' => ['Replacement text makes this subtitle line longer than 84 characters.'],
                ]);
            }

            $updatedCue = [
                ...$cue,
                'cueId' => 'quick-fix-'.str_replace('-', '', (string) Str::uuid()),
                'sourceText' => $updatedSourceText,
                'translatedText' => $updatedSourceText,
                'tokens' => $updatedTokens,
            ];
            unset($updatedCue['romanization']);

            return [$cuePosition, $updatedCue];
        }, attempts: 5);

        // Provider work must not hold database locks or publish a half-finished edit.
        $updatedCue = $this->translationAnalysis->refreshEditedCue(
            $updatedCue,
            $job->source_language,
            $job->target_language,
            $job->include_translation,
            $job->include_romanization,
            selection: SubtitleModel::forJob($job),
        );

        return DB::transaction(function () use ($job, $payload, $cuePosition, $updatedCue): SubtitleTrack {
            $track = $this->lockQuickFixTrack($job, $payload['expectedTrackId']);
            // Read current cues so concurrent word-card updates elsewhere survive.
            $cues = array_values($track->cues);
            $cues[$cuePosition] = $updatedCue;
            $track->update([
                'public_id' => (string) Str::uuid(),
                'cues' => $cues,
                'web_vtt' => $this->webVtt($cues),
            ]);
            $this->costs->recordCueBatch($job, 'enriching', 1, requiredStatus: 'completed');

            return $track->fresh(['job']);
        }, attempts: 5);
    }

    private function lockQuickFixTrack(SubtitleJob $job, string $expectedTrackId): SubtitleTrack
    {
        $user = User::query()->whereKey($job->user_id)->lockForUpdate()->firstOrFail();
        if ($this->billing->activePlan($user) === null) {
            throw BillingEntitlementException::paymentRequired();
        }

        $track = SubtitleTrack::query()
            ->where('subtitle_job_id', $job->getKey())
            ->where('expires_at', '>', now())
            ->whereHas('job', fn ($query) => $query->where('status', 'completed')->where('run_id', $job->run_id))
            ->lockForUpdate()
            ->firstOrFail();

        if (! hash_equals((string) $track->public_id, $expectedTrackId)) {
            throw SubtitleProcessingException::lyricsTrackChanged();
        }

        if ($track->lyricsCorrection()->whereIn('status', ['queued', 'running'])->exists()) {
            throw SubtitleProcessingException::lyricsCorrectionInProgress();
        }

        return $track;
    }

    public function process(int $trackId, string $attemptId, int $expectedRevision, ?int $batchIndex = null): void
    {
        $correction = $this->claimUnit($trackId, $attemptId, $expectedRevision, $batchIndex);

        if ($correction === null) {
            return;
        }

        $job = $correction->track?->job;

        if (! $job instanceof SubtitleJob) {
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);

            return;
        }

        $processedStage = is_array($correction->work_state) ? ($correction->work_state['stage'] ?? null) : null;
        $startedAt = hrtime(true);

        try {
            $this->ensureCorrectionCurrent($correction);
            if ($processedStage === 'analyzing' && $batchIndex === null) {
                $this->dispatchPendingUnits($correction);

                return;
            }
            $nextState = $processedStage === 'analyzing' && $batchIndex !== null
                ? $this->derivedUnit($correction, $job, $correction->work_state, $batchIndex)
                : $this->advanceUnit($correction, $job);
        } catch (SubtitleProcessingException $exception) {
            Log::warning('backend.lyrics_correction_unit_failed', [
                'track_id' => $trackId,
                'subtitle_job_id' => $job->id,
                'attempt_id' => $attemptId,
                'work_revision' => $expectedRevision,
                'stage' => $processedStage,
                'error_code' => $exception->publicCode,
                'reason' => $exception->context['reason'] ?? $exception->publicCode,
            ]);
            if ($exception->isTransient()) {
                throw $exception;
            }

            if ($exception->publicCode === 'lyrics_do_not_match') {
                $this->failAttempt($trackId, $attemptId, 'lyrics_do_not_match', 'These lyrics do not seem to match this song. Check the paste and try again.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);
            } elseif ($exception->publicCode === 'lyrics_incomplete') {
                $this->failAttempt($trackId, $attemptId, 'lyrics_incomplete', 'These lyrics may be incomplete. Your current subtitles are unchanged. Confirm that you want to apply them to the matching sections and keep the existing lyrics elsewhere.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);
            } elseif (($exception->context['reason'] ?? null) === 'excessive_cue_allocation') {
                $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'The lyrics could not be fitted to the existing timing. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);
            } else {
                $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);
            }

            return;
        } catch (BillingEntitlementException $exception) {
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);

            return;
        } catch (Throwable $exception) {
            Log::error('backend.lyrics_correction_failed', [
                'track_id' => $trackId,
                'subtitle_job_id' => $job->id,
                'attempt_id' => $attemptId,
                'stage' => 'processing',
                'exception' => $exception::class,
            ]);
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.', expectedRevision: $expectedRevision, batchIndex: $batchIndex);

            return;
        }

        $this->commitProgress($trackId, $attemptId, $expectedRevision, $job, $processedStage, $nextState);
        Log::info('backend.lyrics_correction_unit_finished', [
            'subtitle_job_id' => $job->id,
            'attempt_id' => $attemptId,
            'work_revision' => $expectedRevision,
            'stage' => $processedStage,
            'batch_index' => $batchIndex,
            'provider' => $job->ai_provider,
            'model' => $job->ai_model,
            'cue_count' => count($nextState['cues'] ?? []),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function failAttempt(
        int $trackId,
        string $attemptId,
        string $errorCode,
        string $message,
        int $expectedRevision,
        ?CarbonInterface $notUpdatedAfter = null,
        ?int $batchIndex = null,
    ): bool {
        if ($batchIndex !== null) {
            return DB::transaction(function () use ($trackId, $attemptId, $errorCode, $message, $expectedRevision, $notUpdatedAfter, $batchIndex): bool {
                $current = SubtitleTrackLyricsCorrection::query()->where('subtitle_track_id', $trackId)
                    ->where('attempt_id', $attemptId)->lockForUpdate()->first();
                if ($current === null || in_array($batchIndex, $current->work_state['completedBatches'] ?? [], true)) {
                    return false;
                }

                return $this->failAttempt($trackId, $attemptId, $errorCode, $message, $expectedRevision, $notUpdatedAfter);
            });
        }

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

        if ($updated === 1) {
            Log::warning('backend.lyrics_correction_failed', [
                'track_id' => $trackId,
                'attempt_id' => $attemptId,
                'work_revision' => $expectedRevision,
                'error_code' => $errorCode,
            ]);
        }

        return $updated === 1;
    }

    public function recoverStalledAttempt(SubtitleTrackLyricsCorrection $attempt, CarbonInterface $cutoff): void
    {
        $recover = DB::transaction(function () use ($attempt, $cutoff): ?SubtitleTrackLyricsCorrection {
            $current = SubtitleTrackLyricsCorrection::query()
                ->whereKey($attempt->id)
                ->where('attempt_id', $attempt->attempt_id)
                ->where('work_revision', $attempt->work_revision)
                ->whereIn('status', ['queued', 'running'])
                ->where('updated_at', '<=', $cutoff)
                ->lockForUpdate()->first();

            if (! $current instanceof SubtitleTrackLyricsCorrection) {
                return null;
            }

            if ($current->work_state['recoveryDispatched'] ?? false) {
                // Queued work may be waiting for capacity; only a second stalled run fails.
                if ($current->status === 'running') {
                    $this->failAttempt($current->subtitle_track_id, $current->attempt_id, 'lyrics_correction_failed', 'Replacement stopped after processing stalled. Your current subtitles are unchanged. Try again.', $current->work_revision, $cutoff);
                }

                return null;
            }

            $current->update(['work_state' => [...($current->work_state ?? []), 'recoveryDispatched' => true]]);

            return $current->fresh(['track.job']);
        }, attempts: 5);

        if ($recover === null || ! $recover->track?->job instanceof SubtitleJob) {
            return;
        }

        try {
            $this->dispatchPendingUnits($recover);
            Log::info('backend.lyrics_correction_recovered', [
                'track_id' => $recover->subtitle_track_id,
                'attempt_id' => $recover->attempt_id,
                'work_revision' => $recover->work_revision,
            ]);
        } catch (Throwable $exception) {
            $this->failAttempt($recover->subtitle_track_id, $recover->attempt_id, 'lyrics_correction_failed', 'Replacement could not be queued. Your current subtitles are unchanged. Try again.', $recover->work_revision);
        }
    }

    private function claimUnit(int $trackId, string $attemptId, int $expectedRevision, ?int $batchIndex): ?SubtitleTrackLyricsCorrection
    {
        return DB::transaction(function () use ($trackId, $attemptId, $expectedRevision, $batchIndex): ?SubtitleTrackLyricsCorrection {
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
                $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'Replacement could not resume. Your current subtitles are unchanged. Try again.', $expectedRevision);

                return null;
            }

            // Resume persisted serial attempts without repeating their completed batches.
            if ($stage === 'analyzing' && ! isset($correction->work_state['completedBatches'])) {
                $state = $correction->work_state;
                $state['completedBatches'] = ($state['batchIndex'] ?? 0) > 0 ? range(0, $state['batchIndex'] - 1) : [];
                $correction->update(['work_state' => $state]);
            }

            if ($batchIndex !== null && ($stage !== 'analyzing'
                || ! isset($correction->work_state['batchPlan'][$batchIndex])
                || in_array($batchIndex, $correction->work_state['completedBatches'] ?? [], true))) {
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

        $draftCues = $this->alignedCues(
            correction: $correction,
            job: $job,
            sourceCues: $track->cues,
            lyrics: $lyrics,
            sourceLanguage: $job->source_language,
            attemptId: $correction->attempt_id,
            allowPartial: (bool) ($correction->work_state['allowPartial'] ?? false),
        );
        $batchPlan = $this->artifacts->batchPlan($draftCues, $job);

        if ($batchPlan === []) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'empty_batch_plan']);
        }

        return [
            'stage' => 'analyzing',
            'batchIndex' => 0,
            'completedBatches' => [],
            'batchPlan' => $batchPlan,
            'cues' => $draftCues,
            'allowPartial' => (bool) ($correction->work_state['allowPartial'] ?? false),
        ];
    }

    private function derivedUnit(SubtitleTrackLyricsCorrection $correction, SubtitleJob $job, array $state, int $batchIndex): array
    {
        $batchPlan = $state['batchPlan'] ?? null;
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

        $this->ensureCorrectionCurrent($correction);
        $result = $this->translationAnalysis->analyzeCueBatch(
            $batch, $cues, $job->source_language, $job->target_language,
            includeTranslation: $this->translationRequested($job),
            includeRomanization: $job->include_romanization && $this->containsNonLatin($batch),
            selection: SubtitleModel::forJob($job),
        );
        $this->ensureCorrectionCurrent($correction);

        return ['stage' => 'analyzing', 'batchIndex' => $batchIndex, 'cues' => $result->cues];
    }

    private function mergeIntoPositions(array &$cues, array $processed, array $bounds): void
    {
        foreach (array_values($processed) as $offset => $cue) {
            $cues[$bounds[0] + $offset] = $cue;
        }
    }

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
        $nextRevision = DB::transaction(function () use ($trackId, $attemptId, $expectedRevision, $job, $processedStage, $nextState): ?int {
            // Match submission's lock order; publication must validate current DB state.
            $user = User::query()->whereKey($job->user_id)->lockForUpdate()->first();
            $currentJob = SubtitleJob::query()->whereKey($job->id)->lockForUpdate()->first();
            $track = SubtitleTrack::query()->whereKey($trackId)->lockForUpdate()->first();
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

            if (! $user instanceof User || $this->billing->activePlan($user) === null
                || ! $currentJob instanceof SubtitleJob || $currentJob->status !== 'completed'
                || ! $track instanceof SubtitleTrack || $track->expires_at->isPast()
                || $currentJob->run_id !== ($locked->work_state['runId'] ?? null)
                || $track->public_id !== ($locked->work_state['trackId'] ?? null)) {
                $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'The track or account changed during replacement. Your current subtitles are unchanged.', $expectedRevision);

                return null;
            }

            if ($processedStage === 'analyzing') {
                $state = $locked->work_state;
                $batchIndex = $nextState['batchIndex'];
                if (in_array($batchIndex, $state['completedBatches'], true)) {
                    return null;
                }
                $this->costs->recordAnalyzedCueBatch($job, count($nextState['cues']), $this->translationRequested($job), $job->include_romanization, requiredStatus: 'completed');
                // Merge only this result into current state, preserving other workers' results.
                $this->mergeIntoPositions($state['cues'], $nextState['cues'], $state['batchPlan'][$batchIndex]);
                $state['completedBatches'][] = $batchIndex;
                unset($state['recoveryDispatched']);
                $pending = array_values(array_diff(array_keys($state['batchPlan']), $state['completedBatches']));
                if ($pending !== []) {
                    $state['batchIndex'] = $pending[0];
                    $locked->update(['work_state' => $state]);

                    return null;
                }
                $nextState = $this->finalizingUnit($state);
            }

            if (($nextState['stage'] ?? null) === 'completed') {
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
            unset($nextState['recoveryDispatched']);
            $locked->update([
                'work_revision' => $nextRevision,
                'work_state' => [...$nextState, 'trackId' => $locked->work_state['trackId'], 'runId' => $locked->work_state['runId']],
            ]);

            return $nextRevision;
        }, attempts: 5);

        if ($nextRevision === null) {
            return;
        }

        try {
            $current = SubtitleTrackLyricsCorrection::query()->where('subtitle_track_id', $trackId)
                ->where('attempt_id', $attemptId)->where('work_revision', $nextRevision)->with('track.job')->first();
            if ($current !== null) {
                $this->dispatchPendingUnits($current);
            }
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

    private function dispatchPendingUnits(SubtitleTrackLyricsCorrection $correction): void
    {
        $state = $correction->work_state ?? [];
        $indices = ($state['stage'] ?? null) === 'analyzing'
            ? array_diff(array_keys($state['batchPlan'] ?? []), $state['completedBatches'] ?? ((int) ($state['batchIndex'] ?? 0) > 0 ? range(0, $state['batchIndex'] - 1) : [])) : [null];
        foreach ($indices as $index) {
            LyricsCorrectionJob::dispatch($correction->subtitle_track_id, $correction->track->job->id,
                $correction->attempt_id, $correction->work_revision, $index)
                ->onQueue(SubtitleQueue::batchNameForJob($correction->track->job));
        }
    }

    private function alignedCues(
        SubtitleTrackLyricsCorrection $correction,
        SubtitleJob $job,
        array $sourceCues,
        string $lyrics,
        string $sourceLanguage,
        string $attemptId,
        bool $allowPartial,
    ): array {
        $parts = $this->lyricsParts($lyrics);
        $input = [
            'sourceLanguage' => $sourceLanguage,
            'cues' => array_map(
                fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'startMs', 'endMs', 'sourceText']),
                array_values($sourceCues),
            ),
            'lyricsParts' => array_map(fn (string $text, int $index): array => ['index' => $index, 'text' => $text], $parts, array_keys($parts)),
            'allowPartial' => $allowPartial,
        ];

        if ($allowPartial) {
            $input['existingParts'] = array_map(
                function (array $cue): array {
                    $cueParts = $this->existingLyricsParts((string) ($cue['sourceText'] ?? ''));

                    return [
                        'cueId' => $cue['cueId'] ?? null,
                        'parts' => array_map(
                            fn (string $text, int $index): array => ['index' => $index, 'text' => $text],
                            $cueParts,
                            array_keys($cueParts),
                        ),
                    ];
                },
                array_values($sourceCues),
            );
        }

        $this->ensureCorrectionCurrent($correction);
        $this->requireActivePlan($job);
        $output = $this->promptAlignment($input, SubtitleModel::forJob($job));
        $this->costs->recordCorrectionAlignment($job);
        $this->ensureCorrectionCurrent($correction);

        return $this->validatedAlignment($sourceCues, $parts, $output, $attemptId, $allowPartial);
    }

    private function ensureCorrectionCurrent(SubtitleTrackLyricsCorrection $correction): void
    {
        $current = SubtitleTrackLyricsCorrection::query()
            ->where('subtitle_track_id', $correction->subtitle_track_id)
            ->where('attempt_id', $correction->attempt_id)
            ->where('work_revision', $correction->work_revision)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if (! $current) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cancelled_or_stale']);
        }

        $track = $correction->track;
        if (! $track instanceof SubtitleTrack || $track->expires_at->isPast()
            || $track->public_id !== ($correction->work_state['trackId'] ?? null)
            || $track->job?->status !== 'completed'
            || $track->job?->run_id !== ($correction->work_state['runId'] ?? null)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'track_changed']);
        }
    }

    private function promptAlignment(array $input, SubtitleModel $selection): array
    {
        try {
            $response = LyricsAlignmentAgent::make(allowPartial: (bool) $input['allowPartial'])
                ->prompt(
                    json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    provider: $selection->provider,
                    model: $selection->model,
                );
            if ($response->steps->last()?->finishReason === FinishReason::Length) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'output_token_limit']);
            }

            return $response->toArray();
        } catch (RateLimitedException $exception) {
            throw SubtitleProcessingException::rateLimited(
                'Subtitle AI processing is temporarily rate limited.',
                [
                    'provider' => $selection->provider,
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
                'provider' => $selection->provider,
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

    /**
     * @return list<string>
     */
    private function splitAlignedText(string $text): array
    {
        $chunks = [];
        $current = '';
        foreach ($this->lyricsParts($text) as $part) {
            if (mb_strlen(SubtitleText::collapseWhitespace($current.$part), 'UTF-8') > 84) {
                if ($current === '' || mb_strlen(SubtitleText::collapseWhitespace($part), 'UTF-8') > 84) {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'unsplittable_lyric_part']);
                }
                $chunks[] = SubtitleText::collapseWhitespace($current);
                $current = '';
            }
            $current .= $part;
        }
        if (SubtitleText::collapseWhitespace($current) !== '') {
            $chunks[] = SubtitleText::collapseWhitespace($current);
        }

        return $chunks;
    }

    /** @return list<string> */
    private function lyricsParts(string $lyrics): array
    {
        preg_match_all('/\S+\s*/u', $lyrics, $matches);
        $parts = [];
        foreach ($matches[0] as $word) {
            if (mb_strlen(rtrim($word), 'UTF-8') <= 84) {
                $parts[] = $word;
            } else {
                // Allow boundaries in unspaced scripts without separating combining marks.
                preg_match_all('/\X/u', $word, $graphemes);
                array_push($parts, ...$graphemes[0]);
            }
        }

        return $parts;
    }

    /** @return list<string> */
    private function existingLyricsParts(string $text): array
    {
        if (preg_match('/\s/u', $text) !== 1 && preg_match('/(?!\p{Latin})\p{L}/u', $text) === 1) {
            preg_match_all('/\X/u', $text, $graphemes);

            return $graphemes[0];
        }

        return $this->lyricsParts($text);
    }

    private function validatedAlignment(array $sourceCues, array $parts, array $output, string $attemptId, bool $allowPartial): array
    {
        if (! array_key_exists('isMatch', $output) || ! is_bool($output['isMatch'])) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_match_classification']);
        }

        if ($output['isMatch'] === false) {
            throw SubtitleProcessingException::lyricsDoNotMatch();
        }

        if (! array_key_exists('isComplete', $output) || ! is_bool($output['isComplete'])) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_completeness_classification']);
        }

        $isComplete = $output['isComplete'];

        if (! $isComplete && ! $allowPartial) {
            throw SubtitleProcessingException::lyricsIncomplete(['reason' => 'ai_classified_incomplete']);
        }

        $outputCues = $output['cues'] ?? null;
        $sourceCues = array_values($sourceCues);

        if (! is_array($outputCues) || $outputCues === [] || count($sourceCues) === 0) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_count_mismatch']);
        }

        if ($isComplete && count($outputCues) > count($sourceCues)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_count_mismatch']);
        }

        if (! $isComplete && count($outputCues) !== count($sourceCues)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'partial_cue_count_mismatch']);
        }

        $sourceCuesById = [];

        foreach ($sourceCues as $sourcePosition => $sourceCue) {
            $sourceCuesById[$sourceCue['cueId']] = ['position' => $sourcePosition, 'cue' => $sourceCue];
        }

        $pastedCursor = 0;
        $draft = [];
        $previousSourcePosition = -1;
        $hasPastedSource = false;
        $hasExistingSource = false;

        foreach (array_values($outputCues) as $position => $outputCue) {
            if (! is_array($outputCue)) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_cue_entry', 'cue_position' => $position]);
            }

            $source = $sourceCuesById[$outputCue['cueId'] ?? ''] ?? null;
            $sourceCue = is_array($source) ? ($source['cue'] ?? null) : null;
            $sourcePosition = is_array($source) ? ($source['position'] ?? null) : null;
            $segments = $outputCue['segments'] ?? null;

            if (! is_array($sourceCue)
                || ! is_int($sourcePosition)
                || $sourcePosition <= $previousSourcePosition
                || ! is_array($segments)
                || $segments === []) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'cue_identity_mismatch', 'cue_position' => $position]);
            }

            $existingParts = $this->existingLyricsParts((string) ($sourceCue['sourceText'] ?? ''));
            $existingCursor = 0;
            $segmentsText = [];
            $previousSegmentSource = null;

            foreach (array_values($segments) as $segmentPosition => $segment) {
                if (! is_array($segment)
                    || ! in_array($segment['source'] ?? null, ['pasted', 'existing'], true)
                    || ! is_int($segment['endPartIndex'] ?? null)
                    || (! $isComplete && (! is_int($segment['startPartIndex'] ?? null)
                        || ! is_string($segment['separator'] ?? null)
                        || ! in_array($segment['separator'], ['', ' '], true)))) {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_source_segment', 'cue_position' => $position, 'segment_position' => $segmentPosition]);
                }

                $segmentSource = $segment['source'];
                $segmentStart = $isComplete ? $pastedCursor : $segment['startPartIndex'];
                $segmentEnd = $segment['endPartIndex'];
                $separator = $isComplete ? '' : $segment['separator'];

                if (($segmentPosition === 0 || $previousSegmentSource === $segmentSource) && $separator !== '') {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_segment_separator', 'cue_position' => $position, 'segment_position' => $segmentPosition]);
                }

                if ($segmentEnd < $segmentStart) {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_source_segment_bounds', 'cue_position' => $position, 'segment_position' => $segmentPosition]);
                }

                if ($segmentSource === 'pasted') {
                    if ($segmentStart !== $pastedCursor || $segmentEnd >= count($parts)) {
                        throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_part_boundary', 'cue_position' => $position, 'segment_position' => $segmentPosition, 'start_part_index' => $pastedCursor, 'part_count' => count($parts)]);
                    }

                    $segmentsText[] = $separator.implode('', array_slice($parts, $segmentStart, $segmentEnd - $segmentStart + 1));
                    $pastedCursor = $segmentEnd + 1;
                    $hasPastedSource = true;
                } else {
                    if ($isComplete || $segmentStart < $existingCursor || $segmentEnd >= count($existingParts)) {
                        throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_existing_source', 'cue_position' => $position, 'segment_position' => $segmentPosition]);
                    }

                    // A gap between existing spans is allowed only when the
                    // preceding span was replaced by pasted text. Otherwise
                    // the plan would silently discard generated lyrics.
                    if ($segmentStart > $existingCursor && $previousSegmentSource !== 'pasted') {
                        throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'unpreserved_existing_parts', 'cue_position' => $position, 'segment_position' => $segmentPosition]);
                    }

                    $segmentsText[] = $separator.implode('', array_slice($existingParts, $segmentStart, $segmentEnd - $segmentStart + 1));
                    $existingCursor = $segmentEnd + 1;
                    $hasExistingSource = true;
                }

                $previousSegmentSource = $segmentSource;
            }

            if (! $isComplete && $previousSegmentSource === 'existing' && $existingCursor !== count($existingParts)) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'unpreserved_existing_parts', 'cue_position' => $position]);
            }

            $text = SubtitleText::collapseWhitespace(implode('', $segmentsText));

            $length = mb_strlen($text, 'UTF-8');

            if ($text === '') {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_cue_text', 'cue_position' => $position, 'character_count' => $length]);
            }

            $start = (int) $sourceCue['startMs'];
            $duration = (int) $sourceCue['endMs'] - $start;
            if ($start < 0 || $duration <= 0) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_cue_timing', 'cue_position' => $position]);
            }
            $sourceLength = mb_strlen(SubtitleText::collapseWhitespace((string) $sourceCue['sourceText']), 'UTF-8');
            $capacity = max(self::MIN_SLOT_CHARACTERS, $sourceLength * self::MAX_SLOT_TEXT_EXPANSION,
                intdiv($duration * self::MAX_SLOT_CHARACTERS_PER_SECOND, 1000));
            if ($length > $capacity) {
                throw SubtitleProcessingException::lyricsCorrectionFailed([
                    'reason' => 'excessive_cue_allocation', 'cue_position' => $position,
                    'duration_ms' => $duration, 'source_character_count' => $sourceLength,
                    'character_count' => $length, 'character_limit' => $capacity,
                ]);
            }

            $previousSourcePosition = $sourcePosition;
            $chunks = $length <= 84 ? [$text] : $this->splitAlignedText($text);
            $lengths = array_map(fn (string $chunk): int => mb_strlen($chunk, 'UTF-8'), $chunks);
            $totalLength = array_sum($lengths);
            $consumedLength = 0;
            foreach ($chunks as $chunkIndex => $chunk) {
                $consumedLength += $lengths[$chunkIndex];
                $end = (int) $sourceCue['startMs'] + intdiv($duration * $consumedLength, $totalLength);
                if ($start < 0 || $end <= $start) {
                    throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_cue_timing', 'cue_position' => $position]);
                }
                $draftIndex = count($draft);
                $draft[] = [
                    'cueId' => sprintf('lyrics-%s-%04d', substr(str_replace('-', '', $attemptId), 0, 8), $draftIndex + 1),
                    'index' => $draftIndex,
                    'startMs' => $start,
                    'endMs' => $end,
                    'sourceText' => $chunk,
                    'translatedText' => $chunk,
                    'tokens' => [],
                ];
                $start = $end;
            }
        }

        if ($pastedCursor !== count($parts)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'unmatched_pasted_parts', 'next_part_index' => $pastedCursor, 'part_count' => count($parts)]);
        }

        if (! $isComplete && (! $hasPastedSource || ! $hasExistingSource)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'partial_sources_not_mixed']);
        }

        return $draft;
    }

    private function sungLyrics(string $normalizedLyrics): string
    {
        $lines = preg_split('/\n/u', $normalizedLyrics) ?: [];
        $lyrics = array_values(array_filter($lines, fn (string $line): bool => ! $this->isSkippableLabelLine($line)));

        if ($lyrics === []) {
            throw new SubtitleProcessingException('validation_failed', 'Lyrics must contain sung text.', 422);
        }

        return implode("\n", $lyrics);
    }

    private function isSkippableLabelLine(string $line): bool
    {
        return preg_match('/^\[(?:intro|outro|verse(?:\s+\d+)?|chorus(?:\s+\d+)?|bridge(?:\s+\d+)?|pre[- ]chorus(?:\s+\d+)?|interlude)\]$/iu', $line) === 1
            || preg_match('/^(?:intro|outro|verse(?:\s+\d+)?|chorus(?:\s+\d+)?|bridge(?:\s+\d+)?|pre[- ]chorus(?:\s+\d+)?|interlude)$/iu', $line) === 1
            || preg_match('/^(?:written by|music by|lyrics by)\s+[\p{L}\p{N}][\p{L}\p{N}\s.,&\'-]{0,78}$/iu', $line) === 1;
    }

    private function translationRequested(SubtitleJob $job): bool
    {
        return $job->include_translation && $job->source_language !== $job->target_language;
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

    /**
     * @param  array<int, array<string, mixed>>  $tokens
     * @return array{0: int, 1: int}|null
     */
    private function tokenSpan(string $sourceText, array $tokens, int $targetIndex): ?array
    {
        $cursor = 0;
        $targetSpan = null;

        foreach ($tokens as $token) {
            if (! is_array($token) || ! is_int($token['index'] ?? null) || ! is_string($token['text'] ?? null)) {
                return null;
            }

            $tokenText = $this->tokenValidator->normalizeTokenText($token['text']);
            $span = $this->findComparableSpan($sourceText, $tokenText, $cursor);

            if ($span === null || preg_match('/[\p{L}\p{N}\p{M}]/u', mb_substr($sourceText, $cursor, $span[0] - $cursor, 'UTF-8')) === 1) {
                return null;
            }

            if ($token['index'] === $targetIndex) {
                $targetSpan = $span;
            }

            $cursor = $span[1];
        }

        return preg_match('/[\p{L}\p{N}\p{M}]/u', mb_substr($sourceText, $cursor, null, 'UTF-8')) === 1
            ? null
            : $targetSpan;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function findComparableSpan(string $sourceText, string $tokenText, int $cursor): ?array
    {
        if ($tokenText === '') {
            return null;
        }

        $sourceLength = mb_strlen($sourceText, 'UTF-8');

        for ($start = $cursor; $start < $sourceLength; $start++) {
            if (preg_match('/^\s$/u', mb_substr($sourceText, $start, 1, 'UTF-8')) === 1) {
                continue;
            }

            for ($end = $start + 1; $end <= $sourceLength; $end++) {
                $candidate = $this->tokenValidator->normalizeTokenText(mb_substr($sourceText, $start, $end - $start, 'UTF-8'));

                if ($candidate === $tokenText) {
                    return [$start, $end];
                }
            }
        }

        return null;
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
