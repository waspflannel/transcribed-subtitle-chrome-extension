<?php

namespace App\Jobs;

use App\Jobs\Middleware\LimitSubtitleBatchConcurrency;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class LyricsCorrectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $trackId,
        public readonly int $subtitleJobId,
        public readonly string $attemptId,
    ) {
        $this->onConnection(SubtitleQueue::connection());
    }

    public function middleware(): array
    {
        return [new LimitSubtitleBatchConcurrency];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(LyricsCorrectionService $corrections): void
    {
        $corrections->process($this->trackId, $this->attemptId);
    }

    public function failed(?Throwable $exception): void
    {
        app(LyricsCorrectionService::class)->failAttempt(
            $this->trackId,
            $this->attemptId,
            'lyrics_correction_failed',
            'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.',
        );
    }
}
