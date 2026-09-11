<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Support\Facades\DB;

class SubtitleJobArtifactStore
{
    public const TRANSCRIPT = 'transcript';

    public const TRANSCRIPT_CHUNK = 'transcript_chunk';

    public const DRAFT_CUES = 'draft_cues';

    public const ANALYZED_CUES = 'analyzed_cues';

    public const MERGED_CUES = 'merged_cues';

    public const ENRICHED_CUES = 'enriched_cues';

    public function __construct(
        private readonly SubtitleRuntimeTracer $tracer,
    ) {}

    public function putTranscript(SubtitleJob $job, TimestampedTranscript $transcript): void
    {
        $this->put($job, self::TRANSCRIPT, [
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
        ]);
    }

    public function transcript(SubtitleJob $job): TimestampedTranscript
    {
        $payload = $this->payload($job, self::TRANSCRIPT);
        $segments = $payload['segments'] ?? null;

        if (! is_array($segments)) {
            $this->failMissingArtifact(self::TRANSCRIPT);
        }

        return new TimestampedTranscript(
            language: $this->stringPayloadValue($payload, 'language', self::TRANSCRIPT),
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
            webVtt: $this->stringPayloadValue($payload, 'webVtt', self::TRANSCRIPT),
        );
    }

    /**
     * Raw per-chunk Scribe payload plus the merge bounds the chunk was cut
     * with. chunkCount is stored on every row so the merge stage can detect
     * a silently missing chunk instead of producing a shorter transcript.
     *
     * @param  array<string, mixed>  $payload
     */
    public function putTranscriptChunk(
        SubtitleJob $job,
        int $chunkIndex,
        int $chunkCount,
        array $payload,
        float $audioStartSeconds,
        float $nominalStartSeconds,
        ?float $nominalEndSeconds,
    ): void {
        $this->put($job, self::TRANSCRIPT_CHUNK, [
            'chunkCount' => $chunkCount,
            'payload' => $payload,
            'audioStartSeconds' => $audioStartSeconds,
            'nominalStartSeconds' => $nominalStartSeconds,
            'nominalEndSeconds' => $nominalEndSeconds,
        ], $chunkIndex);
    }

    /**
     * Every stored transcript chunk in chunk order, shaped for the chunk
     * merger. Fails loudly when any chunk is missing or malformed.
     *
     * @return array<int, array{payload: array<string, mixed>, audioStartSeconds: float, nominalStartSeconds: float, nominalEndSeconds: float|null}>
     */
    public function transcriptChunks(SubtitleJob $job): array
    {
        $artifacts = SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('artifact_type', self::TRANSCRIPT_CHUNK)
            ->where('run_id', $job->run_id)
            ->orderBy('batch_index')
            ->get();

        if ($artifacts->isEmpty()) {
            $this->failMissingArtifact(self::TRANSCRIPT_CHUNK);
        }

        $chunks = [];
        $expectedCount = null;

        foreach ($artifacts as $artifact) {
            $payload = $artifact->payload;
            $chunkPayload = $payload['payload'] ?? null;
            $chunkCount = $payload['chunkCount'] ?? null;

            if (! is_array($chunkPayload) || ! is_int($chunkCount)) {
                $this->failMissingArtifact(self::TRANSCRIPT_CHUNK);
            }

            $expectedCount ??= $chunkCount;
            $nominalEnd = $payload['nominalEndSeconds'] ?? null;

            $chunks[] = [
                'payload' => $chunkPayload,
                'audioStartSeconds' => (float) ($payload['audioStartSeconds'] ?? 0.0),
                'nominalStartSeconds' => (float) ($payload['nominalStartSeconds'] ?? 0.0),
                'nominalEndSeconds' => is_numeric($nominalEnd) ? (float) $nominalEnd : null,
            ];
        }

        if (count($chunks) !== $expectedCount) {
            $this->failMissingArtifact(self::TRANSCRIPT_CHUNK);
        }

        return $chunks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function putCueCollection(
        SubtitleJob $job,
        string $artifactType,
        array $cues,
        string $sourceDialect = 'unknown',
    ): void {
        $cues = array_values($cues);

        $this->put($job, $artifactType, [
            'cues' => $cues,
            'sourceDialect' => $sourceDialect,
            'batchPlan' => $this->batchPlan($cues, $job),
        ]);
    }

    public function hasArtifact(SubtitleJob $job, string $artifactType, int $batchIndex = 0): bool
    {
        return SubtitleJobArtifact::query()->where('subtitle_job_id', $job->id)
            ->where('run_id', $job->run_id)->where('artifact_type', $artifactType)
            ->where('batch_index', $batchIndex)->exists();
    }

    public function cueCollection(SubtitleJob $job, string $artifactType): CueEnrichmentResult
    {
        $payload = $this->payload($job, $artifactType);
        $cues = $payload['cues'] ?? null;

        if (! is_array($cues)) {
            $this->failMissingArtifact($artifactType);
        }

        return new CueEnrichmentResult(
            cues: array_values($cues),
            sourceDialect: is_string($payload['sourceDialect'] ?? null) ? $payload['sourceDialect'] : 'unknown',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cueBatch(SubtitleJob $job, string $artifactType, int $batchIndex): array
    {
        $payload = $this->payload($job, $artifactType);
        $cues = $payload['cues'] ?? null;

        if (! is_array($cues)) {
            $this->failMissingArtifact($artifactType);
        }

        $cues = array_values($cues);
        $bounds = $this->payloadBatchPlan($payload, $artifactType)[$batchIndex] ?? null;

        if ($bounds === null) {
            $this->failMissingArtifact($artifactType);
        }

        [$start, $end] = $bounds;
        $batch = array_slice($cues, $start, $end - $start + 1);

        if ($batch === []) {
            $this->failMissingArtifact($artifactType);
        }

        return $batch;
    }

    public function cueBatchResult(SubtitleJob $job, string $artifactType, int $batchIndex): CueEnrichmentResult
    {
        $payload = $this->payload($job, $artifactType, $batchIndex);
        $cues = $payload['cues'] ?? null;

        if (! is_array($cues)) {
            $this->failMissingArtifact($artifactType);
        }

        return new CueEnrichmentResult(
            cues: array_values($cues),
            sourceDialect: is_string($payload['sourceDialect'] ?? null) ? $payload['sourceDialect'] : 'unknown',
        );
    }

    public function batchCount(SubtitleJob $job, string $artifactType): int
    {
        $payload = $this->payload($job, $artifactType);
        $cues = $payload['cues'] ?? null;

        if (! is_array($cues)) {
            $this->failMissingArtifact($artifactType);
        }

        return max(1, count($this->payloadBatchPlan($payload, $artifactType)));
    }

    public function cueCount(SubtitleJob $job, string $artifactType): int
    {
        $payload = $this->payload($job, $artifactType);
        $cues = $payload['cues'] ?? null;

        if (! is_array($cues)) {
            $this->failMissingArtifact($artifactType);
        }

        return count($cues);
    }

    public function putCueBatchResult(
        SubtitleJob $job,
        string $artifactType,
        int $batchIndex,
        CueEnrichmentResult $result,
    ): void {
        $this->put($job, $artifactType, [
            'cues' => array_values($result->cues),
            'sourceDialect' => $result->sourceDialect,
        ], $batchIndex);
    }

    public function cueResultFromBatchArtifacts(SubtitleJob $job, string $artifactType): CueEnrichmentResult
    {
        $artifacts = SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('artifact_type', $artifactType)
            ->where('run_id', $job->run_id)
            ->orderBy('batch_index')
            ->get();

        $sourceType = $artifactType === self::ANALYZED_CUES ? self::DRAFT_CUES : self::MERGED_CUES;
        $expectedCount = $this->batchCount($job, $sourceType);
        if ($artifacts->pluck('batch_index')->all() !== range(0, $expectedCount - 1)) {
            $this->failMissingArtifact($artifactType);
        }

        $cues = [];
        $sourceDialect = 'unknown';

        foreach ($artifacts as $artifact) {
            $payload = $artifact->payload;
            $batchCues = $payload['cues'] ?? null;

            if (! is_array($batchCues)) {
                $this->failMissingArtifact($artifactType);
            }

            array_push($cues, ...array_values($batchCues));

            if ($sourceDialect === 'unknown' && is_string($payload['sourceDialect'] ?? null) && $payload['sourceDialect'] !== 'unknown') {
                $sourceDialect = $payload['sourceDialect'];
            }
        }

        return new CueEnrichmentResult($cues, $sourceDialect);
    }

    public function deleteForJob(SubtitleJob $job): void
    {
        $artifactCount = SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('run_id', $job->run_id)
            ->count();
        $startedAtMs = $this->currentTimeMs();

        SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('run_id', $job->run_id)
            ->delete();

        $this->tracer->jobEvent($job, 'artifact.deleted', [
            'artifact_count' => $artifactCount,
            'duration_ms' => $this->durationMs($startedAtMs),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(SubtitleJob $job, string $artifactType, array $payload, int $batchIndex = 0): void
    {
        $startedAtMs = $this->currentTimeMs();

        DB::transaction(function () use ($job, $artifactType, $payload, $batchIndex, $startedAtMs): void {
            $currentJob = SubtitleJob::query()
                ->with('track')
                ->whereKey($job->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $currentJob instanceof SubtitleJob
                || $currentJob->run_id !== $job->run_id
                || $currentJob->status !== 'running'
                || $currentJob->hasReadyTrack()
            ) {
                return;
            }

            SubtitleJobArtifact::query()->updateOrCreate(
                [
                    'subtitle_job_id' => $currentJob->id,
                    'artifact_type' => $artifactType,
                    'batch_index' => $batchIndex,
                    'run_id' => $currentJob->run_id,
                ],
                ['payload' => $payload],
            );

            $this->tracer->jobEvent($currentJob, 'artifact.written', [
                'artifact_type' => $artifactType,
                'batch_index' => $batchIndex,
                'duration_ms' => $this->durationMs($startedAtMs),
            ]);
        }, attempts: 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SubtitleJob $job, string $artifactType, int $batchIndex = 0): array
    {
        $startedAtMs = $this->currentTimeMs();
        $artifact = SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('artifact_type', $artifactType)
            ->where('batch_index', $batchIndex)
            ->where('run_id', $job->run_id)
            ->first();

        $payload = $artifact?->payload;

        if (! is_array($payload)) {
            $this->failMissingArtifact($artifactType);
        }

        $this->tracer->jobEvent($job, 'artifact.read', [
            'artifact_type' => $artifactType,
            'batch_index' => $batchIndex,
            'duration_ms' => $this->durationMs($startedAtMs),
        ]);

        return $payload;
    }

    /**
     * Group cues into batches by cumulative sourceText length rather than a
     * fixed cue count, while retaining a maximum cue count per request.
     *
     * @param  array<int, array<string, mixed>>  $cues
     * @return array<int, array{0: int, 1: int}> inclusive [start, end] index pairs
     */
    public function batchPlan(array $cues, ?SubtitleJob $job = null): array
    {
        $count = count($cues);

        if ($count === 0) {
            return [];
        }

        $charBudget = $this->batchCharBudget();
        $maxCues = $this->maxCuesPerBatch();

        $plan = [];
        $start = 0;
        $chars = 0;

        foreach ($cues as $index => $cue) {
            $length = mb_strlen(is_string($cue['sourceText'] ?? null) ? $cue['sourceText'] : '');
            $size = $index - $start;

            // Close the current batch before adding a cue that would push it
            // past the character budget or the max cue count -- but never emit
            // an empty batch, so a single over-budget cue forms its own batch.
            if ($size > 0 && ($chars + $length > $charBudget || $size >= $maxCues)) {
                $plan[] = [$start, $index - 1];
                $start = $index;
                $chars = 0;
            }

            $chars += $length;
        }

        $plan[] = [$start, $count - 1];

        return config('subtitles.enrichment.balanced_batches', false)
            ? $this->balancedPlan($cues, $plan, $job) : $plan;
    }

    /** Balance estimated response work without adding calls or raising input limits. */
    private function balancedPlan(array $cues, array $baseline, ?SubtitleJob $job): array
    {
        if (count($baseline) < 2) {
            return $baseline;
        }

        $lengths = array_map(fn (array $cue): int => mb_strlen(is_string($cue['sourceText'] ?? null) ? $cue['sourceText'] : ''), $cues);
        $weights = array_map(function (array $cue) use ($job): int {
            $text = is_string($cue['sourceText'] ?? null) ? $cue['sourceText'] : '';
            $nonLatin = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Thai}\p{Lao}]/u', $text);
            $words = preg_match_all('/[\p{L}\p{N}]+/u', $text);

            return 32 + mb_strlen($text) + 24 * max($words, (int) ceil($nonLatin / 2))
                + ($job?->include_translation ? mb_strlen($text) : 0)
                + ($job?->include_romanization ? 4 * $nonLatin : 0);
        }, $cues);
        $partition = function (int $budget) use ($weights, $lengths): array {
            $result = [];
            $start = $chars = $work = 0;
            foreach ($weights as $index => $weight) {
                if ($index > $start && ($work + $weight > $budget
                    || $chars + $lengths[$index] > $this->batchCharBudget()
                    || $index - $start >= $this->maxCuesPerBatch())) {
                    $result[] = [$start, $index - 1];
                    $start = $index;
                    $chars = $work = 0;
                }
                $chars += $lengths[$index];
                $work += $weight;
            }
            $result[] = [$start, count($weights) - 1];

            return $result;
        };
        $low = max($weights);
        $high = array_sum($weights);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if (count($partition($mid)) <= count($baseline)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $partition($low);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{0: int, 1: int}> inclusive [start, end] index pairs
     */
    private function payloadBatchPlan(array $payload, string $artifactType): array
    {
        $plan = $payload['batchPlan'] ?? null;

        if (is_array($plan)) {
            $resolved = [];

            foreach ($plan as $entry) {
                if (is_array($entry) && isset($entry[0], $entry[1])) {
                    $resolved[] = [(int) $entry[0], (int) $entry[1]];
                }
            }

            if ($resolved !== []) {
                return $resolved;
            }
        }

        $this->failMissingArtifact($artifactType);
    }

    private function batchCharBudget(): int
    {
        return max(1, (int) config('subtitles.enrichment.cue_batch_char_budget', 1000));
    }

    private function maxCuesPerBatch(): int
    {
        return max(1, (int) config('subtitles.enrichment.cue_batch_max_cues', 20));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringPayloadValue(array $payload, string $field, string $artifactType): string
    {
        if (! is_string($payload[$field] ?? null)) {
            $this->failMissingArtifact($artifactType);
        }

        return $payload[$field];
    }

    private function failMissingArtifact(string $artifactType): never
    {
        throw SubtitleProcessingException::enrichmentFailed(
            'Subtitle processing state is incomplete.',
            ['artifact_type' => $artifactType],
        );
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function durationMs(int $startedAtMs): int
    {
        return max(0, $this->currentTimeMs() - $startedAtMs);
    }
}
