<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\TranslationAnalysis\CueEnrichmentResult;

class SubtitleJobArtifactStore
{
    public const TRANSCRIPT = 'transcript';

    public const DRAFT_CUES = 'draft_cues';

    public const TOKENIZED_CUES = 'tokenized_cues';

    public const TRANSLATED_CUES = 'translated_cues';

    public const ROMANIZED_CUES = 'romanized_cues';

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
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function putCueCollection(
        SubtitleJob $job,
        string $artifactType,
        array $cues,
        string $sourceDialect = 'unknown',
        ?int $batchSize = null,
    ): void {
        $this->put($job, $artifactType, [
            'cues' => array_values($cues),
            'sourceDialect' => $sourceDialect,
            'batchSize' => $batchSize ?? $this->batchSize(),
        ]);
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
        $batchSize = $this->payloadBatchSize($payload);

        if (! is_array($cues)) {
            $this->failMissingArtifact($artifactType);
        }

        $batch = array_chunk(array_values($cues), $batchSize)[$batchIndex] ?? null;

        if ($batch === null) {
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

        return max(1, (int) ceil(count($cues) / $this->payloadBatchSize($payload)));
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
            ->orderBy('batch_index')
            ->get();

        if ($artifacts->isEmpty()) {
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
            ->count();
        $startedAtMs = $this->currentTimeMs();

        SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
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

        SubtitleJobArtifact::query()->updateOrCreate(
            [
                'subtitle_job_id' => $job->id,
                'artifact_type' => $artifactType,
                'batch_index' => $batchIndex,
            ],
            ['payload' => $payload],
        );

        $this->tracer->jobEvent($job, 'artifact.written', [
            'artifact_type' => $artifactType,
            'batch_index' => $batchIndex,
            'duration_ms' => $this->durationMs($startedAtMs),
        ]);
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
     * @param  array<string, mixed>  $payload
     */
    private function payloadBatchSize(array $payload): int
    {
        $batchSize = $payload['batchSize'] ?? null;

        return is_int($batchSize) && $batchSize > 0 ? $batchSize : $this->batchSize();
    }

    private function batchSize(): int
    {
        return max(1, (int) config('subtitles.enrichment.cue_batch_size', 10));
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
