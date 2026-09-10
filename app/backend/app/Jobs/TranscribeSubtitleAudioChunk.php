<?php

namespace App\Jobs;

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
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Third generation stage, fanned out per chunk: uploads one audio chunk to
 * Scribe and stores its allowlisted word payload as a job artifact. A null
 * audio file represents one whole-video URL ingestion, guarded by job mode.
 * The batch
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

    public int $maxExceptions = 1;

    /** Covers the Scribe request timeout (600s) plus upload slack. */
    public int $timeout = 660;

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
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->queuedAtMs = $queuedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
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
        );
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
