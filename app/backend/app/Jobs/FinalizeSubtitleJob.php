<?php

namespace App\Jobs;

use App\Jobs\Middleware\LimitSubtitleInstallConcurrency;
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

class FinalizeSubtitleJob implements ShouldQueue
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
        public readonly bool $useEnrichedCues,
        public readonly string $runId,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->onQueue(SubtitleQueue::name());
        $this->queuedAtMs = $queuedAtMs ?? $this->currentTimeMs();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new LimitSubtitleInstallConcurrency];
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->persistGeneratedSubtitleTrack($this->subtitleJobId, $this->useEnrichedCues, $this->runId, $this->queuedAtMs);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
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
