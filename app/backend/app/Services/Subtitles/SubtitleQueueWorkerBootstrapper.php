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

        $workerCount = $this->workerCount();

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $pid = $this->startWorkerProcess();

            Log::info('backend.subtitle_queue_worker_started', [
                'connection' => SubtitleGenerationPipeline::connection(),
                'queue_driver' => $this->queueDriver(),
                'database_driver' => $this->databaseDriver(),
                'queue' => SubtitleGenerationPipeline::queue(),
                'worker_number' => $worker + 1,
                'worker_count' => $workerCount,
                'worker_pid' => $pid,
                'worker_max_time_seconds' => max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900)),
                'start_result' => $pid === null ? 'pid_unavailable' : 'started',
            ]);
        }

        Log::info('backend.subtitle_queue_workers_started', [
            'connection' => SubtitleGenerationPipeline::connection(),
            'queue_driver' => $this->queueDriver(),
            'database_driver' => $this->databaseDriver(),
            'queue' => SubtitleGenerationPipeline::queue(),
            'worker_count' => $workerCount,
            'worker_max_time_seconds' => max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900)),
        ]);
    }

    private function shouldStartWorkers(): bool
    {
        return in_array(SubtitleGenerationPipeline::connection(), ['database', 'redis'], true)
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

    protected function startWorkerProcess(): ?int
    {
        if (! function_exists('exec')) {
            Log::warning('backend.subtitle_queue_worker_start_failed', [
                'reason' => 'exec_unavailable',
                'connection' => SubtitleGenerationPipeline::connection(),
                'queue' => SubtitleGenerationPipeline::queue(),
            ]);

            return null;
        }

        $output = [];
        $exitCode = 1;
        @exec($this->workerCommand(), $output, $exitCode);

        if ($exitCode !== 0) {
            Log::warning('backend.subtitle_queue_worker_start_failed', [
                'connection' => SubtitleGenerationPipeline::connection(),
                'queue_driver' => $this->queueDriver(),
                'database_driver' => $this->databaseDriver(),
                'queue' => SubtitleGenerationPipeline::queue(),
                'exit_code' => $exitCode,
            ]);

            return null;
        }

        return $this->parsePid($output[0] ?? null);
    }

    protected function workerCommand(): string
    {
        $args = $this->workerArguments();

        if (DIRECTORY_SEPARATOR === '\\') {
            $script = '$process = Start-Process -FilePath '.$this->quotePowerShell($args[0])
                .' -ArgumentList @('.implode(', ', array_map(
                    fn (string $arg): string => $this->quotePowerShell($arg),
                    array_slice($args, 1),
                )).') -WorkingDirectory '.$this->quotePowerShell(base_path())
                .' -WindowStyle Hidden -PassThru; $process.Id';

            return 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '.escapeshellarg($script);
        }

        return 'sh -c '.escapeshellarg($this->quoteCommand($args).' > /dev/null 2>&1 & echo $!');
    }

    /**
     * @return array<int, string>
     */
    private function workerArguments(): array
    {
        return [
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            SubtitleGenerationPipeline::connection(),
            '--queue='.SubtitleGenerationPipeline::queue().',default',
            '--sleep='.max(0, (int) config('subtitles.queue.auto_worker_sleep_seconds', 1)),
            '--tries=1',
            '--timeout='.max(60, (int) config('subtitles.queue.auto_worker_timeout_seconds', 1200)),
            '--max-time='.max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900)),
        ];
    }

    /**
     * @param  array<int, string>  $args
     */
    private function quoteCommand(array $args): string
    {
        return implode(' ', array_map('escapeshellarg', $args));
    }

    private function quotePowerShell(string $arg): string
    {
        return "'".str_replace("'", "''", $arg)."'";
    }

    private function parsePid(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $pid = trim($value);

        return ctype_digit($pid) ? (int) $pid : null;
    }

    protected function workerCount(): int
    {
        if ($this->usesSqliteQueueDatabase()) {
            return 1;
        }

        return max(1, (int) config('subtitles.queue.auto_worker_count', 6));
    }

    private function usesSqliteQueueDatabase(): bool
    {
        if (SubtitleGenerationPipeline::connection() !== 'database') {
            return false;
        }

        $queueDatabase = config('queue.connections.database.connection') ?: config('database.default');

        return config('database.connections.'.$queueDatabase.'.driver') === 'sqlite';
    }

    private function queueDriver(): string
    {
        return (string) config('queue.connections.'.SubtitleGenerationPipeline::connection().'.driver', SubtitleGenerationPipeline::connection());
    }

    private function databaseDriver(): string
    {
        $database = (string) config('database.default');

        return (string) config('database.connections.'.$database.'.driver', $database);
    }
}
