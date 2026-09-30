<?php

namespace App\Jobs;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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

    public bool $failOnTimeout = true;

    public int $timeout;

    public ?int $batchIndex = null;

    public ?int $queuedAtMs = null;

    public function __construct(
        public readonly int $trackId,
        public readonly int $subtitleJobId,
        public readonly string $attemptId,
        public readonly int $expectedRevision,
        ?int $batchIndex = null,
    ) {
        $this->batchIndex = $batchIndex;
        $this->queuedAtMs = (int) round(microtime(true) * 1000);
        $this->onConnection(SubtitleQueue::connection());
        $this->timeout = self::timeoutSeconds();
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('lyrics-correction:'.$this->attemptId.':'.($this->batchIndex ?? 'alignment')))
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
        Log::info('backend.lyrics_correction_unit_started', [
            'subtitle_job_id' => $this->subtitleJobId,
            'attempt_id' => $this->attemptId,
            'work_revision' => $this->expectedRevision,
            'batch_index' => $this->batchIndex,
            'queued_at_ms' => $this->queuedAtMs,
            'queue_wait_ms' => $this->queuedAtMs === null ? null : max(0, (int) round(microtime(true) * 1000) - $this->queuedAtMs),
        ]);
        try {
            $corrections->process($this->trackId, $this->attemptId, $this->expectedRevision, $this->batchIndex);
        } catch (SubtitleProcessingException $exception) {
            if ($this->job !== null && ($exception->context['reason'] ?? null) === 'provider_admission') {
                $this->release(max(1, (int) ($exception->context['retry_after_seconds'] ?? 10)));

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LyricsCorrectionService::class)->failAttempt(
            $this->trackId,
            $this->attemptId,
            'lyrics_correction_failed',
            'Pasted lyrics could not be applied. Your current subtitles are unchanged. Try again.',
            expectedRevision: $this->expectedRevision,
            batchIndex: $this->batchIndex,
        );
    }

    public static function timeoutSeconds(): int
    {
        $providerTimeout = max(1, (int) config('subtitles.enrichment.timeout_seconds', 120));
        $timeout = $providerTimeout + 60;
        $workerTimeout = max(1, (int) config('subtitles.queue.worker_timeout_seconds', 1200));
        $retryAfter = (int) config('queue.connections.'.SubtitleQueue::connection().'.retry_after', 0);
        $driver = (string) config('queue.connections.'.SubtitleQueue::connection().'.driver');

        if ($timeout >= $workerTimeout || ($driver !== 'sync' && $retryAfter <= $timeout)) {
            throw new LogicException('Lyrics correction timeout must cover its single provider request and stay below the worker timeout and queue retry_after.');
        }

        return $timeout;
    }
}
