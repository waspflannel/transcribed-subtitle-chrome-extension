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

class ProcessSubtitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 1200;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->onQueue(SubtitleQueue::generationName());
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
        $pipeline->transcribeSourceAudioAndDispatchAnalysis($this->subtitleJobId, $this->runId, $this->queuedAtMs);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
            $this->subtitleJobId,
            'preparing',
            $exception ?? new RuntimeException('Subtitle processing job failed.'),
            $this->runId,
        );
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
