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

class TranslateSubtitleCueBatch implements ShouldQueue
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
        $this->onQueue(SubtitleGenerationPipeline::QUEUE);
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->translateBatch($this->subtitleJobId, $this->batchIndex);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleGenerationPipeline::class)->failJob(
            $this->subtitleJobId,
            'translating',
            $exception ?? new RuntimeException('Subtitle translation batch failed.'),
        );
    }
}
