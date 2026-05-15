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

class ContinueSubtitleJobAfterRomanization implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $subtitleJobId,
    ) {
        $this->onQueue(SubtitleGenerationPipeline::QUEUE);
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->continueAfterRomanization($this->subtitleJobId);
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleGenerationPipeline::class)->failJob(
            $this->subtitleJobId,
            'romanizing',
            $exception ?? new RuntimeException('Subtitle romanization continuation failed.'),
        );
    }
}
