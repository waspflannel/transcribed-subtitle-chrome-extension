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

    public int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly int $batchIndex,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::batchConnection());
        $this->queuedAtMs = $queuedAtMs ?? $this->currentTimeMs();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
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
        // Chained jobs get their queue payload only after their predecessor
        // finishes. Constructor time includes dependency work, not queue wait.
        $publishedAt = $this->job?->payload()['createdAt'] ?? null;

        if (is_numeric($publishedAt)) {
            $this->queuedAtMs = (int) round((float) $publishedAt * 1000);
        }

        try {
            $this->process($processor);
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
            $this->stage(),
            $exception ?? new RuntimeException($this->failureMessage()),
            $this->runId,
            ['batch_index' => $this->batchIndex],
        );
    }

    abstract protected function process(SubtitleCueBatchProcessor $processor): void;

    abstract protected function stage(): string;

    abstract protected function failureMessage(): string;

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
