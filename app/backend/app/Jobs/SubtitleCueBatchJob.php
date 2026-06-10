<?php

namespace App\Jobs;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\Middleware\LimitSubtitleBatchConcurrency;
use App\Services\Subtitles\SubtitleCueBatchProcessor;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

abstract class SubtitleCueBatchJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly int $batchIndex,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->queuedAtMs = $queuedAtMs ?? $this->currentTimeMs();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $middleware = [new LimitSubtitleBatchConcurrency, new SkipIfBatchCancelled];

        if ($this->globalAiRateLimitEnabled()) {
            $middleware[] = new RateLimited('subtitle-ai-batch');
        }

        return $middleware;
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
        try {
            $this->process($processor);
        } catch (Throwable $exception) {
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
            $this->stage(),
            $exception ?? new RuntimeException($this->failureMessage()),
            $this->runId,
            ['batch_index' => $this->batchIndex],
        );
    }

    abstract protected function process(SubtitleCueBatchProcessor $processor): void;

    abstract protected function stage(): string;

    abstract protected function failureMessage(): string;

    private function globalAiRateLimitEnabled(): bool
    {
        if ((int) config('subtitles.enrichment.global_rate_limit_per_minute', 0) <= 0) {
            return false;
        }

        // The sync driver cannot release jobs back to a queue, so rate limiting
        // (like the per-user concurrency gate) only applies to real queues.
        return (string) config('queue.connections.'.SubtitleQueue::connection().'.driver') !== 'sync';
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
