<?php

namespace App\Services\Subtitles;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\LyricsCorrectionJob;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\ClaudeCode\ClaudeCodeService;
use App\Services\Codex\CodexService;
use App\Services\InstanceSettings;
use App\Services\Text\NoSpaceArtifactBoundary;
use App\Services\Text\SubtitleText;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use IntlBreakIterator;
use Laravel\Ai\Responses\Data\FinishReason;
use Normalizer;
use Throwable;

final class LyricsCorrectionService
{
    private const STAGES = ['aligning', 'analyzing', 'finalizing'];

    public function __construct(
        private readonly SubtitleJobArtifactStore $artifacts,
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly SubtitleProviderCostRecorder $costs,
        private readonly LearningTokenOutputValidator $tokenValidator,
    ) {}

    public function normalizeLyrics(string $lyrics): string
    {
        // Composed form keeps pasted text comparable with transcripts (か plus a combining voicing mark becomes が).
        $lines = preg_split('/\R/u', Normalizer::normalize($lyrics, Normalizer::FORM_C) ?: $lyrics) ?: [];
        $normalized = [];

        foreach ($lines as $line) {
            $line = SubtitleText::collapseWhitespace($line);

            if ($line !== '') {
                $normalized[] = $line;
            }
        }

        $normalizedLyrics = implode("\n", $normalized);

        if ($normalizedLyrics === '') {
            throw new SubtitleProcessingException('validation_failed', 'Lyrics must not be empty.', 422);
        }

        return $normalizedLyrics;
    }

    public function submit(SubtitleJob $job, string $lyrics, string $expectedTrackId, ?SubtitleModel $selection = null): SubtitleTrackLyricsCorrection
    {
        if ($selection !== null) {
            app(InstanceSettings::class)->requireProviderKey($selection->provider);
        }
        $selection ??= SubtitleModel::forJob($job);
        $lock = Cache::store(SubtitleQueue::concurrencyCacheStore())->lock('subtitle-track-edit:'.$job->id, 30);
        if (! $lock->get()) {
            throw SubtitleProcessingException::lyricsCorrectionInProgress(['reason' => 'track_edit_in_progress']);
        }
        try {
            return $this->submitLocked($job, $lyrics, $expectedTrackId, $selection);
        } finally {
            $lock->release();
        }
    }

    private function submitLocked(SubtitleJob $job, string $lyrics, string $expectedTrackId, SubtitleModel $selection): SubtitleTrackLyricsCorrection
    {
        $trackId = $job->track?->getKey();
        $attemptId = (string) Str::uuid();

        $correction = DB::transaction(function () use ($job, $lyrics, $trackId, $attemptId, $expectedTrackId, $selection): SubtitleTrackLyricsCorrection {
            $job = SubtitleJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            $track = SubtitleTrack::query()
                ->whereKey($trackId)
                ->whereBelongsTo($job, 'job')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->lockForUpdate()
                ->first();

            if (! $track instanceof SubtitleTrack || $job->status !== 'completed') {
                abort(404);
            }

            if (! hash_equals((string) $track->public_id, $expectedTrackId)) {
                throw SubtitleProcessingException::lyricsTrackChanged();
            }

            if ($track->lyricsCorrection()->whereIn('status', ['queued', 'running'])->exists()) {
                throw SubtitleProcessingException::lyricsCorrectionInProgress();
            }

            $normalizedLyrics = $this->normalizeLyrics($lyrics);
            $correction = $track->lyricsCorrection()->first();
            $initialState = [
                'stage' => 'aligning',
                'trackId' => $track->public_id,
                'runId' => $job->run_id,
            ];

            if ($correction instanceof SubtitleTrackLyricsCorrection) {
                $correction->update([
                    'attempt_id' => $attemptId,
                    'ai_provider' => $selection->provider,
                    'ai_model' => $selection->model,
                    'ai_fast_mode' => $selection->fastMode,
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
                    'ai_provider' => $selection->provider,
                    'ai_model' => $selection->model,
                    'ai_fast_mode' => $selection->fastMode,
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
                ->onQueue(SubtitleQueue::batchName());
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

    public function cancel(SubtitleJob $job, string $attemptId): SubtitleTrackLyricsCorrection
    {
        return DB::transaction(function () use ($job, $attemptId): SubtitleTrackLyricsCorrection {
            $track = SubtitleTrack::query()
                ->where('subtitle_job_id', $job->getKey())
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
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
        string $cueId,
        int $tokenIndex,
        array $payload,
    ): SubtitleTrack {
        $lock = Cache::store(SubtitleQueue::concurrencyCacheStore())->lock(
            'subtitle-track-edit:'.$job->id,
            max(60, (int) config('subtitles.enrichment.timeout_seconds', 120) + 60),
        );
        if (! $lock->get()) {
            throw SubtitleProcessingException::lyricsCorrectionInProgress(['reason' => 'track_edit_in_progress']);
        }
        try {
            return $this->applyQuickFix($job, $cueId, $tokenIndex, $payload);
        } finally {
            $lock->release();
        }
    }

    private function applyQuickFix(SubtitleJob $job, string $cueId, int $tokenIndex, array $payload): SubtitleTrack
    {
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

            if ($span === null) {
                // Without an exact span, an edit would drop punctuation or words the tokens do not cover.
                throw new SubtitleProcessingException('lyrics_correction_failed', 'This word cannot be edited because the line text does not match its words. Your subtitles are unchanged.', 422, ['reason' => 'token_span_not_found']);
            }

            $updatedSourceText = mb_substr($sourceText, 0, $span[0], 'UTF-8')
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

            // Only this cue changes, so only its own timing needs checking before the paid call.
            if ((int) $cue['startMs'] < 0 || (int) $cue['endMs'] <= (int) $cue['startMs']) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_timing_or_text']);
            }

            $updatedCue = [
                ...$cue,
                'cueId' => 'quick-fix-'.str_replace('-', '', (string) Str::uuid()),
                'sourceText' => $updatedSourceText,
                'tokens' => $updatedTokens,
            ];
            // The old translation and readings describe the replaced word; the refresh rebuilds them.
            unset($updatedCue['romanization'], $updatedCue['translatedText']);

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
            job: $job,
        );

        return DB::transaction(function () use ($job, $payload, $cuePosition, $updatedCue): SubtitleTrack {
            $track = $this->lockQuickFixTrack($job, $payload['expectedTrackId']);
            // Read current cues so concurrent word-card updates elsewhere survive.
            $cues = array_values($track->cues);
            $cues[$cuePosition] = $updatedCue;
            $track->update([
                'public_id' => (string) Str::uuid(),
                'cues' => $cues,
                'web_vtt' => SubtitleWebVttFormatter::fromCues($cues),
            ]);
            // One call rebuilt word cards plus the enabled translation and readings.
            foreach (array_keys(array_filter([
                'enriching' => true,
                'translating' => $this->translationRequested($job),
                'romanizing' => $this->romanizationRequested($job, [$updatedCue]),
            ])) as $stage) {
                $this->costs->recordCueBatch($job, $stage, 1, requiredStatus: 'completed');
            }

            return $track->fresh(['job']);
        }, attempts: 5);
    }

    private function lockQuickFixTrack(SubtitleJob $job, string $expectedTrackId): SubtitleTrack
    {
        // Cost recording and retention updates also acquire the job before its track.
        SubtitleJobLock::current($job->id, $job->run_id);
        $track = SubtitleTrack::query()
            ->where('subtitle_job_id', $job->getKey())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
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

            $message = $processedStage === 'analyzing'
                ? 'Translations and word details could not be generated. Your current subtitles are unchanged. Try again.'
                : 'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.';
            $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', $message, expectedRevision: $expectedRevision, batchIndex: $batchIndex);

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
        $selection = match ($processedStage) {
            'aligning', 'analyzing' => SubtitleModel::forCorrection($correction),
            default => null,
        };
        Log::info('backend.lyrics_correction_unit_finished', [
            'subtitle_job_id' => $job->id,
            'attempt_id' => $attemptId,
            'work_revision' => $expectedRevision,
            'stage' => $processedStage,
            'batch_index' => $batchIndex,
            'provider' => $selection?->provider,
            'model' => $selection?->model,
            ...($selection?->provider === 'codex' ? ['fast_mode' => $selection->fastMode] : []),
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

        $this->ensureCorrectionCurrent($correction);
        $result = $this->translationAnalysis->analyzeCueBatch(
            $batch, $cues, $job->source_language, $job->target_language,
            includeTranslation: $this->translationRequested($job),
            includeRomanization: $this->romanizationRequested($job, $batch),
            selection: SubtitleModel::forCorrection($correction),
            validateOutput: false,
            job: $job,
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
            'webVtt' => SubtitleWebVttFormatter::fromCues($cues),
        ];
    }

    /**
     * @param  array<string, mixed>  $nextState
     */
    private function commitProgress(int $trackId, string $attemptId, int $expectedRevision, SubtitleJob $job, ?string $processedStage, array $nextState): void
    {
        $nextRevision = DB::transaction(function () use ($trackId, $attemptId, $expectedRevision, $job, $processedStage, $nextState): ?int {
            // Match submission's lock order; publication must validate current DB state.
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

            if (! $currentJob instanceof SubtitleJob || $currentJob->status !== 'completed'
                || ! $track instanceof SubtitleTrack || $track->isExpired()
                || $currentJob->run_id !== ($locked->work_state['runId'] ?? null)
                || $track->public_id !== ($locked->work_state['trackId'] ?? null)) {
                $this->failAttempt($trackId, $attemptId, 'lyrics_correction_failed', 'The track changed during replacement. Your current subtitles are unchanged.', $expectedRevision);

                return null;
            }

            if ($processedStage === 'analyzing') {
                $state = $locked->work_state;
                $batchIndex = $nextState['batchIndex'];
                if (in_array($batchIndex, $state['completedBatches'], true)) {
                    return null;
                }
                $this->costs->recordAnalyzedCueBatch($job, count($nextState['cues']), $this->translationRequested($job), $this->romanizationRequested($job, $nextState['cues']), requiredStatus: 'completed', selection: SubtitleModel::forCorrection($locked));
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
                ->onQueue(SubtitleQueue::batchName());
        }
    }

    private function alignedCues(
        SubtitleTrackLyricsCorrection $correction,
        SubtitleJob $job,
        array $sourceCues,
        string $lyrics,
        string $sourceLanguage,
        string $attemptId,
    ): array {
        $parts = $this->lyricsParts($lyrics);
        $input = [
            'sourceLanguage' => $sourceLanguage,
            'cues' => array_map(
                fn (array $cue): array => Arr::only($cue, ['cueId', 'index', 'startMs', 'endMs', 'sourceText']),
                array_values($sourceCues),
            ),
            'lyricsParts' => array_map(fn (string $text, int $index): array => ['index' => $index, 'text' => $text], $parts, array_keys($parts)),
        ];

        $this->ensureCorrectionCurrent($correction);
        $selection = SubtitleModel::forCorrection($correction);
        $output = $this->promptAlignment($input, $selection, $job);
        $this->costs->recordCorrectionAlignment($job, $selection);
        $this->ensureCorrectionCurrent($correction);

        return $this->cuesFromAlignment($sourceCues, $parts, $output, $attemptId);
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
        if (! $track instanceof SubtitleTrack || $track->isExpired()
            || $track->public_id !== ($correction->work_state['trackId'] ?? null)
            || $track->job?->status !== 'completed'
            || $track->job?->run_id !== ($correction->work_state['runId'] ?? null)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'track_changed']);
        }
    }

    private function promptAlignment(array $input, SubtitleModel $selection, ?SubtitleJob $job = null): array
    {
        try {
            $agent = LyricsAlignmentAgent::make(cueCount: count($input['cues']));
            if ($selection->provider === 'codex') {
                return app(ProviderAdmission::class)->run($selection->provider, $job,
                    fn (): array => app(CodexService::class)->prompt($agent, $input, $selection));
            }
            if ($selection->provider === 'claude') {
                return app(ProviderAdmission::class)->run($selection->provider, $job,
                    fn (): array => app(ClaudeCodeService::class)->prompt($agent, $input, $selection));
            }
            $response = app(ProviderAdmission::class)->run($selection->provider, $job, fn () => $agent->prompt(
                json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                provider: $selection->provider,
                model: $selection->model,
            ));
            if ($response->steps->last()?->finishReason === FinishReason::Length) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'alignment_output_truncated']);
            }

            return $response->toArray();
        } catch (Throwable $exception) {
            throw ProviderExceptionPolicy::classify($exception, [
                'provider' => $selection->provider,
                'adapter' => SubtitleModel::adapter($selection->provider),
                'agent' => LyricsAlignmentAgent::class,
            ]);
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
                    return [$text];
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
            $pieces = preg_match('/['.NoSpaceArtifactBoundary::SCRIPT_CLASS.']/u', $word) === 1 ? $this->wordPieces($word) : [$word];
            foreach ($pieces as $piece) {
                if (mb_strlen(rtrim($piece), 'UTF-8') <= 84) {
                    $parts[] = $piece;
                } else {
                    // Allow boundaries in overlong unspaced text without separating combining marks.
                    preg_match_all('/\X/u', $piece, $graphemes);
                    array_push($parts, ...$graphemes[0]);
                }
            }
        }

        return $parts;
    }

    /**
     * Split unspaced scripts (Japanese, Chinese, Thai) at dictionary word boundaries.
     * Punctuation and trailing whitespace stay attached to a neighboring word.
     *
     * @return list<string>
     */
    private function wordPieces(string $word): array
    {
        $breaks = IntlBreakIterator::createWordInstance();
        $breaks->setText($word);
        $pieces = [];
        $pending = '';
        $start = 0;
        foreach ($breaks as $end) {
            if ($end === 0) {
                continue;
            }
            $piece = substr($word, $start, $end - $start);
            $start = $end;
            if (preg_match('/[\p{L}\p{N}]/u', $piece) === 1) {
                $pieces[] = $pending.$piece;
                $pending = '';
            } elseif ($pieces === []) {
                $pending .= $piece;
            } else {
                $pieces[array_key_last($pieces)] .= $piece;
            }
        }

        return $pending === '' ? $pieces : [...$pieces, $pending];
    }

    private function cuesFromAlignment(array $sourceCues, array $parts, array $output, string $attemptId): array
    {
        $knownIds = array_flip(array_column($sourceCues, 'cueId'));
        $texts = [];
        $cursor = 0;
        $previousSlot = -1;
        $partCount = count($parts);
        $allocations = $output['cues'] ?? null;
        if (! is_array($allocations) || $allocations === [] || count($allocations) > count($sourceCues)) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_alignment']);
        }
        foreach ($allocations as $cue) {
            $id = is_array($cue) ? ($cue['cueId'] ?? null) : null;
            $end = is_array($cue) ? ($cue['endPartIndex'] ?? null) : null;
            if (! is_string($id) || ! isset($knownIds[$id]) || $knownIds[$id] <= $previousSlot
                || ! is_int($end) || $end < $cursor || $end >= $partCount) {
                throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_alignment']);
            }
            $previousSlot = $knownIds[$id];
            $texts[$id] = implode('', array_slice($parts, $cursor, $end - $cursor + 1));
            $cursor = $end + 1;
        }
        if ($cursor !== $partCount) {
            throw SubtitleProcessingException::lyricsCorrectionFailed(['reason' => 'invalid_alignment']);
        }

        $draft = [];
        foreach ($sourceCues as $sourceCue) {
            $text = SubtitleText::collapseWhitespace($texts[$sourceCue['cueId']] ?? '');
            $start = (int) $sourceCue['startMs'];
            $duration = (int) $sourceCue['endMs'] - $start;
            if ($text === '' || $start < 0 || $duration <= 0) {
                continue;
            }
            $chunks = $this->splitAlignedText($text);
            $lengths = array_map(fn (string $chunk): int => mb_strlen($chunk, 'UTF-8'), $chunks);
            $totalLength = array_sum($lengths);
            // A tiny source slot cannot display multiple pieces; keep its text together.
            if ($duration < $totalLength) {
                $chunks = [$text];
                $lengths = [$totalLength];
            }
            $consumedLength = 0;
            foreach ($chunks as $chunkIndex => $chunk) {
                $consumedLength += $lengths[$chunkIndex];
                $end = (int) $sourceCue['startMs'] + intdiv($duration * $consumedLength, $totalLength);
                // Like draft generation, symbol-only lines such as ♪ have no learner words to analyze.
                if (preg_match('/[\p{L}\p{N}]/u', $chunk) === 1) {
                    $index = count($draft);
                    $draft[] = [
                        'cueId' => sprintf('lyrics-%s-%04d', substr(str_replace('-', '', $attemptId), 0, 8), $index + 1),
                        'index' => $index, 'startMs' => $start, 'endMs' => $end,
                        'sourceText' => $chunk, 'translatedText' => $chunk, 'tokens' => [],
                    ];
                }
                $start = $end;
            }
        }

        return $draft;
    }

    private function translationRequested(SubtitleJob $job): bool
    {
        return $job->include_translation && $job->source_language !== $job->target_language;
    }

    /** Romanization is only requested, and costed, for batches containing non-Latin letters. */
    private function romanizationRequested(SubtitleJob $job, array $cues): bool
    {
        return $job->include_romanization && SubtitleText::hasNonLatinCues($cues);
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
        // Fold typographic quotes only for comparison; offsets still refer to the original text.
        $quotes = ['‘' => "'", '’' => "'", '“' => '"', '”' => '"'];
        $tokenText = strtr($tokenText, $quotes);

        if ($tokenText === '') {
            return null;
        }

        $sourceLength = mb_strlen($sourceText, 'UTF-8');

        for ($start = $cursor; $start < $sourceLength; $start++) {
            if (preg_match('/^\s$/u', mb_substr($sourceText, $start, 1, 'UTF-8')) === 1) {
                continue;
            }

            for ($end = $start + 1; $end <= $sourceLength; $end++) {
                $candidate = strtr($this->tokenValidator->normalizeTokenText(mb_substr($sourceText, $start, $end - $start, 'UTF-8')), $quotes);

                if ($candidate === $tokenText) {
                    return [$start, $end];
                }
            }
        }

        return null;
    }
}
