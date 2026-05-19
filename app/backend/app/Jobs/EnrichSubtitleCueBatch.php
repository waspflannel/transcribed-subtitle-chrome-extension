<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class EnrichSubtitleCueBatch implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly int $batchIndex,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleGenerationPipeline::connection());
        $this->onQueue(SubtitleGenerationPipeline::queue());
        $this->queuedAtMs = $queuedAtMs ?? $this->currentTimeMs();
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
        $pipeline->enrichBatch($this->subtitleJobId, $this->batchIndex, $this->runId, $this->queuedAtMs);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleGenerationPipeline::class)->failJob(
            $this->subtitleJobId,
            'enriching',
            $exception ?? new RuntimeException('Subtitle enrichment batch failed.'),
            $this->runId,
            ['batch_index' => $this->batchIndex],
        );
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
