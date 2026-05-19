<?php

namespace App\Services\Subtitles;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubtitleQueueWorkerBootstrapper
{
    private const START_LOCK_PREFIX = 'subtitle-ai-worker-bootstrap-started';

    private const WORKER_PIDS_PREFIX = 'subtitle-ai-worker-bootstrap-pids';

    public function startIfNeeded(): void
    {
        if (! $this->shouldStartWorkers()) {
            return;
        }

        $workerCount = $this->workerCount();
        $liveWorkerPids = $this->liveWorkerPids();

        if (count($liveWorkerPids) >= $workerCount) {
            return;
        }

        if (count($liveWorkerPids) < $workerCount) {
            Cache::forget($this->startLockKey());
        }

        if (! $this->claimStartLock()) {
            return;
        }

        $tracer = app(SubtitleRuntimeTracer::class);
        $startedWorkerPids = $liveWorkerPids;

        for ($worker = count($liveWorkerPids); $worker < $workerCount; $worker++) {
            $pid = $this->startWorkerProcess();

            if ($pid !== null) {
                $startedWorkerPids[] = $pid;
            }

            $tracer->record(null, 'worker.started', [
                'connection' => SubtitleGenerationPipeline::connection(),
                'queue_driver' => $this->queueDriver(),
                'database_driver' => $this->databaseDriver(),
                'queue' => SubtitleGenerationPipeline::queue(),
                'worker_number' => $worker + 1,
                'worker_count' => $workerCount,
                'existing_worker_count' => count($liveWorkerPids),
                'worker_pid' => $pid,
                'worker_max_time_seconds' => max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900)),
                'start_result' => $pid === null ? 'pid_unavailable' : 'started',
            ], logName: 'backend.subtitle_queue_worker_started');
        }

        $this->rememberWorkerPids($startedWorkerPids);

        $tracer->record(null, 'workers.started', [
            'connection' => SubtitleGenerationPipeline::connection(),
            'queue_driver' => $this->queueDriver(),
            'database_driver' => $this->databaseDriver(),
            'queue' => SubtitleGenerationPipeline::queue(),
            'worker_count' => $workerCount,
            'existing_worker_count' => count($liveWorkerPids),
            'started_worker_count' => max(0, $workerCount - count($liveWorkerPids)),
            'worker_max_time_seconds' => max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900)),
        ], logName: 'backend.subtitle_queue_workers_started');
    }

    private function shouldStartWorkers(): bool
    {
        return in_array(SubtitleGenerationPipeline::connection(), ['database', 'redis'], true)
            && (bool) config('subtitles.queue.auto_start_workers', false);
    }

    private function claimStartLock(): bool
    {
        try {
            return Cache::add($this->startLockKey(), true, now()->addSeconds($this->workerMaxTimeSeconds()));
        } catch (Throwable $exception) {
            Log::warning('backend.subtitle_queue_worker_lock_failed', [
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    protected function startWorkerProcess(): ?int
    {
        if (! $this->canStartWorkerProcesses()) {
            Log::warning('backend.subtitle_queue_worker_start_skipped', [
                'reason' => 'worker_processes_disabled',
                'connection' => SubtitleGenerationPipeline::connection(),
                'queue' => SubtitleGenerationPipeline::queue(),
            ]);

            return null;
        }

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

    protected function canStartWorkerProcesses(): bool
    {
        return ! app()->environment('testing')
            || (bool) config('subtitles.queue.allow_worker_processes_in_testing', false);
    }

    /**
     * @return array<int, int>
     */
    private function liveWorkerPids(): array
    {
        $cachedPids = Cache::get($this->workerPidsKey(), []);

        if (! is_array($cachedPids)) {
            Cache::forget($this->workerPidsKey());

            return [];
        }

        $livePids = [];

        foreach ($cachedPids as $pid) {
            $pid = is_numeric($pid) ? (int) $pid : 0;

            if ($pid > 0 && $this->isProcessRunning($pid)) {
                $livePids[] = $pid;
            }
        }

        $livePids = array_values(array_unique($livePids));

        if ($livePids === []) {
            Cache::forget($this->workerPidsKey());
        } else {
            $this->rememberWorkerPids($livePids);
        }

        return $livePids;
    }

    /**
     * @param  array<int, int|null>  $pids
     */
    private function rememberWorkerPids(array $pids): void
    {
        $pids = array_values(array_unique(array_filter(
            array_map(fn (mixed $pid): int => (int) $pid, $pids),
            fn (int $pid): bool => $pid > 0,
        )));

        if ($pids === []) {
            Cache::forget($this->workerPidsKey());

            return;
        }

        Cache::put($this->workerPidsKey(), $pids, now()->addSeconds($this->workerMaxTimeSeconds()));
    }

    protected function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0 || ! function_exists('exec')) {
            return false;
        }

        $output = [];
        $exitCode = 1;

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('tasklist /FI "PID eq '.$pid.'" /NH', $output, $exitCode);

            return $exitCode === 0
                && collect($output)->contains(fn (string $line): bool => preg_match('/\b'.$pid.'\b/', $line) === 1);
        }

        if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
            return true;
        }

        @exec('ps -p '.escapeshellarg((string) $pid).' -o pid=', $output, $exitCode);

        return $exitCode === 0
            && collect($output)->contains(fn (string $line): bool => trim($line) === (string) $pid);
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
            '--max-time='.$this->workerMaxTimeSeconds(),
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

    private function workerMaxTimeSeconds(): int
    {
        return max(60, (int) config('subtitles.queue.auto_worker_max_time_seconds', 900));
    }

    private function startLockKey(): string
    {
        return self::START_LOCK_PREFIX.':'.$this->runtimeKey();
    }

    private function workerPidsKey(): string
    {
        return self::WORKER_PIDS_PREFIX.':'.$this->runtimeKey();
    }

    private function runtimeKey(): string
    {
        return sha1(implode('|', [
            SubtitleGenerationPipeline::connection(),
            SubtitleGenerationPipeline::queue(),
            $this->queueDriver(),
            $this->databaseDriver(),
            $this->workerCount(),
            $this->workerMaxTimeSeconds(),
        ]));
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
