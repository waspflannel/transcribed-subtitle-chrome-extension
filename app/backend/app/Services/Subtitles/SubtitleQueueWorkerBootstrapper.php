<?php

namespace App\Services\Subtitles;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubtitleQueueWorkerBootstrapper
{
    private const START_LOCK_KEY = 'subtitle-ai-worker-bootstrap-started';

    public function startIfNeeded(): void
    {
        if (! $this->shouldStartWorkers()) {
            return;
        }

        if (! $this->claimStartLock()) {
            return;
        }

        $workerCount = max(1, (int) config('subtitles.queue.auto_worker_count', 3));

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $this->startWorkerProcess();
        }

        Log::info('backend.subtitle_queue_workers_started', [
            'connection' => SubtitleGenerationPipeline::connection(),
            'queue' => SubtitleGenerationPipeline::QUEUE,
            'worker_count' => $workerCount,
        ]);
    }

    private function shouldStartWorkers(): bool
    {
        return SubtitleGenerationPipeline::connection() === 'database'
            && (bool) config('subtitles.queue.auto_start_workers', false);
    }

    private function claimStartLock(): bool
    {
        try {
            return Cache::add(self::START_LOCK_KEY, true, now()->addSeconds(30));
        } catch (Throwable $exception) {
            Log::warning('backend.subtitle_queue_worker_lock_failed', [
                'exception' => $exception::class,
            ]);

            return true;
        }
    }

    private function startWorkerProcess(): void
    {
        $command = $this->workerCommand();
        $process = @popen($command, 'r');

        if ($process === false) {
            Log::warning('backend.subtitle_queue_worker_start_failed');

            return;
        }

        @pclose($process);
    }

    private function workerCommand(): string
    {
        $args = [
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            SubtitleGenerationPipeline::connection(),
            '--queue='.SubtitleGenerationPipeline::QUEUE.',default',
            '--sleep='.max(0, (int) config('subtitles.queue.auto_worker_sleep_seconds', 1)),
            '--tries=1',
            '--timeout='.max(60, (int) config('subtitles.queue.auto_worker_timeout_seconds', 1200)),
            '--max-time='.max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900)),
        ];

        if (DIRECTORY_SEPARATOR === '\\') {
            return 'cmd /C start "" /B '.$this->quoteCommand($args).' >NUL 2>NUL';
        }

        return $this->quoteCommand($args).' > /dev/null 2>&1 &';
    }

    /**
     * @param  array<int, string>  $args
     */
    private function quoteCommand(array $args): string
    {
        return implode(' ', array_map('escapeshellarg', $args));
    }
}
