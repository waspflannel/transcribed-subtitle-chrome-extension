<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class FinalizeSubtitleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly bool $useEnrichedCues,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleGenerationPipeline::connection());
        $this->onQueue(SubtitleGenerationPipeline::queue());
        $this->queuedAtMs = $queuedAtMs ?? $this->currentTimeMs();
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->finalize($this->subtitleJobId, $this->useEnrichedCues, $this->runId, $this->queuedAtMs);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleGenerationPipeline::class)->failJob(
            $this->subtitleJobId,
            'finalizing',
            $exception ?? new RuntimeException('Subtitle finalization failed.'),
            $this->runId,
        );
    }

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
