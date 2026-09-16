<?php

namespace App\Jobs;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Third generation stage, fanned out per chunk: extracts and uploads one audio chunk to
 * Scribe and stores its allowlisted word payload as a job artifact. A null
 * audio file represents one whole-video URL ingestion, guarded by job mode.
 * audioEndSeconds marks a source file that still needs slicing. The batch
 * completion dispatches MergeSubtitleTranscript once every chunk landed.
 */
class TranscribeSubtitleAudioChunk implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    /** Covers extraction (60s), Scribe (600s), and upload/persistence slack. */
    public int $timeout = 720;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly int $chunkIndex,
        public readonly int $chunkCount,
        public readonly string $runId,
        public readonly ?TemporaryAudioFile $chunkAudio,
        public readonly float $audioStartSeconds,
        public readonly float $nominalStartSeconds,
        public readonly ?float $nominalEndSeconds,
        ?int $queuedAtMs = null,
        public readonly ?float $nextAudioStartSeconds = null,
        public readonly ?float $audioEndSeconds = null,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->queuedAtMs = $queuedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('subtitle-transcription:'.$this->runId.':'.$this->chunkIndex))
                ->releaseAfter(1)->expireAfter(780),
            new SkipIfBatchCancelled,
        ];
    }

    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        try {
            $pipeline->transcribeAudioChunk(
                subtitleJobId: $this->subtitleJobId,
                runId: $this->runId,
                chunkIndex: $this->chunkIndex,
                chunkCount: $this->chunkCount,
                chunkAudio: $this->chunkAudio,
                audioStartSeconds: $this->audioStartSeconds,
                nominalStartSeconds: $this->nominalStartSeconds,
                nominalEndSeconds: $this->nominalEndSeconds,
                queuedAtMs: $this->queuedAtMs,
                nextAudioStartSeconds: $this->nextAudioStartSeconds,
                audioEndSeconds: $this->audioEndSeconds ?? null,
            );
        } catch (Throwable $exception) {
            if ($this->job !== null && $exception instanceof SubtitleProcessingException
                && ($exception->context['reason'] ?? null) === 'provider_admission') {
                $this->release(max(1, (int) ($exception->context['retry_after_seconds'] ?? 10)));

                return;
            }
            if ($this->job === null || ($exception instanceof SubtitleProcessingException && $exception->isTransient())) {
                throw $exception;
            }
            $this->fail($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
            $this->subtitleJobId,
            'transcribing',
            $exception ?? new RuntimeException('Subtitle transcription chunk job failed.'),
            $this->runId,
            ['chunk_index' => $this->chunkIndex],
        );
    }
}
