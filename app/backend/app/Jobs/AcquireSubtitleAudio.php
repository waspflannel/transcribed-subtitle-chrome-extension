<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * First generation stage: claims the preparing job and downloads the source
 * audio (or short-circuits on a cached transcript). Optimization and
 * transcription continue as their own queue jobs so no worker is held
 * through the full external-I/O span.
 */
class AcquireSubtitleAudio implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    /** Covers the yt-dlp metadata (60s) and download (600s) process timeouts. */
    public int $timeout = 900;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->onQueue(SubtitleQueue::generationName());
        $this->queuedAtMs = $queuedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->acquireAudioAndContinue($this->subtitleJobId, $this->runId, $this->queuedAtMs);
    }

    /**
     * A redelivered acquisition may re-claim its run, so deliveries of one run
     * never overlap. The lock expires before Redis redelivers a killed worker's job.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('subtitle-acquisition:'.$this->runId))
            ->releaseAfter(2)->expireAfter(960)];
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
            $this->subtitleJobId,
            'acquiring-audio',
            $exception ?? new RuntimeException('Subtitle audio acquisition job failed.'),
            $this->runId,
        );
    }
}
