<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

abstract class SubtitleBatchContinuationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
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
        return [];
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $this->process($pipeline);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
            $this->subtitleJobId,
            $this->stage(),
            $exception ?? new RuntimeException($this->failureMessage()),
            $this->runId,
        );
    }

    abstract protected function process(SubtitleGenerationPipeline $pipeline): void;

    abstract protected function stage(): string;

    abstract protected function failureMessage(): string;

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
