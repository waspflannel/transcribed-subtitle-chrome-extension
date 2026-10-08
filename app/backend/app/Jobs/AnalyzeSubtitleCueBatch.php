<?php

namespace App\Jobs;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
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

class AnalyzeSubtitleCueBatch implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly int $batchIndex,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::batchConnection());
        $this->queuedAtMs = $queuedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Drop twin deliveries; the first owns the result. A crashed worker's
        // lock expires after its timeout and before the 360s reservation ends.
        return [
            (new WithoutOverlapping('subtitle-analysis:'.$this->runId.':'.$this->batchIndex))
                ->dontRelease()->expireAfter(330),
            new SkipIfBatchCancelled,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(SubtitleCueBatchProcessor $processor): void
    {
        // Measure wait from queue publication, which can follow construction.
        $publishedAt = $this->job?->payload()['createdAt'] ?? null;

        if (is_numeric($publishedAt)) {
            $this->queuedAtMs = (int) round((float) $publishedAt * 1000);
        }

        try {
            $processor->analyzeCueBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
        } catch (Throwable $exception) {
            if ($this->job !== null && $exception instanceof SubtitleProcessingException
                && ($exception->context['reason'] ?? null) === 'provider_admission') {
                $this->release(max(1, (int) ($exception->context['retry_after_seconds'] ?? 10)));

                return;
            }
            // Transient provider failures (429/5xx/timeout) are released back to
            // the queue with backoff until $maxExceptions is exhausted; anything
            // else (validation, programming errors) still fails the job loudly
            // on the first occurrence.
            if ($this->job === null
                || ($exception instanceof SubtitleProcessingException && $exception->isTransient())) {
                throw $exception;
            }

            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
            $this->subtitleJobId,
            'tokenizing',
            $exception ?? new RuntimeException('Subtitle analysis batch failed.'),
            $this->runId,
            ['batch_index' => $this->batchIndex],
        );
    }
}
