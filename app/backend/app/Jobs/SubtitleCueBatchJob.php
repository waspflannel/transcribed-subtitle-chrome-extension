<?php

namespace App\Jobs;

use App\Jobs\Middleware\LimitSubtitleBatchConcurrency;
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

    public int $maxExceptions = 1;

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
        return [new LimitSubtitleBatchConcurrency, new SkipIfBatchCancelled];
    }

    public function handle(SubtitleCueBatchProcessor $processor): void
    {
        $this->process($processor);
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
