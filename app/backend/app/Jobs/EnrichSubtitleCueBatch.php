<?php

namespace App\Jobs;

use App\Services\Subtitles\SubtitleGenerationPipeline;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly int $batchIndex,
    ) {
        $this->onConnection(SubtitleGenerationPipeline::connection());
        $this->onQueue(SubtitleGenerationPipeline::QUEUE);
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->enrichBatch($this->subtitleJobId, $this->batchIndex);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleGenerationPipeline::class)->failJob(
            $this->subtitleJobId,
            'enriching',
            $exception ?? new RuntimeException('Subtitle enrichment batch failed.'),
        );
    }
}
