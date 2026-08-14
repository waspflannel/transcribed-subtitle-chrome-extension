<?php

namespace App\Jobs;

use App\Jobs\Middleware\LimitSubtitleBatchConcurrency;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use LogicException;
use Throwable;

class LyricsCorrectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MAX_BACKOFF_SECONDS = 60;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout;

    public function __construct(
        public readonly int $trackId,
        public readonly int $subtitleJobId,
        public readonly string $attemptId,
        public readonly int $expectedRevision,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->timeout = self::timeoutSeconds();
    }

    public function middleware(): array
    {
        return [
            new LimitSubtitleBatchConcurrency,
            (new WithoutOverlapping('lyrics-correction:'.$this->attemptId))
                ->expireAfter(self::timeoutSeconds() + 60)
                ->releaseAfter(5),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, self::MAX_BACKOFF_SECONDS];
    }

    public function handle(LyricsCorrectionService $corrections): void
    {
        $corrections->process($this->trackId, $this->attemptId, $this->expectedRevision);
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

    public static function timeoutSeconds(): int
    {
        $providerTimeout = max(1, (int) config('subtitles.enrichment.timeout_seconds', 120));
        $timeout = ($providerTimeout * 2) + 60;
        $workerTimeout = max(1, (int) config('subtitles.queue.worker_timeout_seconds', 1200));
        $retryAfter = (int) config('queue.connections.'.SubtitleQueue::connection().'.retry_after', 0);
        $driver = (string) config('queue.connections.'.SubtitleQueue::connection().'.driver');

        if ($timeout >= $workerTimeout || ($driver !== 'sync' && $retryAfter <= $timeout)) {
            throw new LogicException('Lyrics correction timeout must cover its two-call provider unit and stay below the worker timeout and queue retry_after.');
        }

        return $timeout;
    }
}
