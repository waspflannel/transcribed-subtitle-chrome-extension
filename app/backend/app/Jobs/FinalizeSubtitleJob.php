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

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly bool $useEnrichedCues,
    ) {
        $this->onConnection(SubtitleGenerationPipeline::connection());
        $this->onQueue(SubtitleGenerationPipeline::QUEUE);
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->finalize($this->subtitleJobId, $this->useEnrichedCues);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleGenerationPipeline::class)->failJob(
            $this->subtitleJobId,
            'finalizing',
            $exception ?? new RuntimeException('Subtitle finalization failed.'),
        );
    }
}
