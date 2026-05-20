<?php

namespace App\Services\Subtitles;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class SubtitleQueueWorkerBootstrapper
{
    private const CACHE_KEY = 'subtitles:auto-workers';

    private const LOCK_KEY = 'subtitles:auto-workers:lock';

    private const WORKER_NAME = 'subtitle-auto-worker';

    /**
     * Ensure local subtitle queue workers exist after a generate request queues work.
     */
    public function ensureRunning(): void
    {
        if (! $this->shouldAutoStart()) {
            return;
        }

        try {
            $lock = Cache::lock(self::LOCK_KEY, $this->lockSeconds());

            if (! $lock->get()) {
                Log::info('backend.subtitle_worker_auto_start_skipped', [
                    'reason' => 'lock_busy',
                    'queue_connection' => SubtitleQueue::connection(),
                    'queues' => SubtitleQueue::workerQueueList(),
                ]);

                return;
            }

            try {
                $this->syncWorkers();
            } finally {
                $lock->release();
            }
        } catch (Throwable $exception) {
            Log::warning('backend.subtitle_worker_auto_start_failed', [
                'reason' => 'unexpected_exception',
                'exception' => $exception::class,
                'queue_connection' => SubtitleQueue::connection(),
                'queues' => SubtitleQueue::workerQueueList(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function autoStartSummary(): array
    {
        return [
            'enabled' => (bool) config('subtitles.queue.auto_start.enabled', true),
            'targetWorkerCount' => $this->targetWorkerCount(),
            'workerName' => self::WORKER_NAME,
            'queueConnection' => SubtitleQueue::connection(),
            'queues' => SubtitleQueue::workerQueueList(),
            'maxTimeSeconds' => $this->maxTimeSeconds(),
            'timeoutSeconds' => $this->timeoutSeconds(),
            'memoryMb' => $this->memoryMb(),
            'tries' => $this->tries(),
        ];
    }

    private function syncWorkers(): void
    {
        $targetWorkerCount = $this->targetWorkerCount();
        $runningWorkers = $this->runningWorkers($this->storedWorkers());
        $missingWorkerCount = max(0, $targetWorkerCount - count($runningWorkers));
        $startedWorkers = [];

        for ($index = 0; $index < $missingWorkerCount; $index++) {
            $startedWorker = $this->startWorker();

            if ($startedWorker !== null) {
                $startedWorkers[] = $startedWorker;
            }
        }

        $workers = [
            ...$runningWorkers,
            ...$startedWorkers,
        ];

        Cache::put(self::CACHE_KEY, $workers, now()->addSeconds($this->maxTimeSeconds() + 120));

        Log::info('backend.subtitle_worker_auto_start_checked', [
            'queue_connection' => SubtitleQueue::connection(),
            'queue_driver' => $this->queueDriver(),
            'queues' => SubtitleQueue::workerQueueList(),
            'target_worker_count' => $targetWorkerCount,
            'running_worker_count' => count($runningWorkers),
            'started_worker_count' => count($startedWorkers),
            'worker_pids' => array_values(array_filter(array_map(
                fn (array $worker): ?int => $worker['pid'] ?? null,
                $workers,
            ))),
            'max_time_seconds' => $this->maxTimeSeconds(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $workers
     * @return array<int, array<string, mixed>>
     */
    private function runningWorkers(array $workers): array
    {
        return array_values(array_filter(
            $workers,
            fn (array $worker): bool => is_int($worker['pid'] ?? null)
                && $this->isWorkerProcessRunning($worker['pid']),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedWorkers(): array
    {
        $workers = Cache::get(self::CACHE_KEY, []);

        return is_array($workers) ? $workers : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function startWorker(): ?array
    {
        $result = PHP_OS_FAMILY === 'Windows'
            ? $this->startWindowsWorker()
            : $this->startUnixWorker();

        $pid = $this->parsePid($result->output());

        if (! $result->successful() || $pid === null) {
            Log::warning('backend.subtitle_worker_auto_start_failed', [
                'reason' => 'process_start_failed',
                'exit_code' => $result->exitCode(),
                'queue_connection' => SubtitleQueue::connection(),
                'queues' => SubtitleQueue::workerQueueList(),
            ]);

            return null;
        }

        $worker = [
            'pid' => $pid,
            'started_at' => now()->toJSON(),
            'queue_connection' => SubtitleQueue::connection(),
            'queues' => SubtitleQueue::workerQueueList(),
            'worker_name' => self::WORKER_NAME,
            'max_time_seconds' => $this->maxTimeSeconds(),
            'tries' => $this->tries(),
        ];

        Log::info('backend.subtitle_worker_auto_started', $worker);

        return $worker;
    }

    private function startWindowsWorker(): ProcessResult
    {
        [$stdout, $stderr] = $this->workerLogPaths();
        $arguments = $this->powershellArray($this->workerArguments());
        $script = implode('; ', [
            '$arguments = '.$arguments,
            '$process = Start-Process -FilePath '.$this->powershellString(PHP_BINARY)
                .' -ArgumentList $arguments'
                .' -WorkingDirectory '.$this->powershellString(base_path())
                .' -WindowStyle Hidden'
                .' -RedirectStandardOutput '.$this->powershellString($stdout)
                .' -RedirectStandardError '.$this->powershellString($stderr)
                .' -PassThru',
            'Write-Output $process.Id',
        ]);

        return Process::timeout(10)->run([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ]);
    }

    private function startUnixWorker(): ProcessResult
    {
        [$stdout, $stderr] = $this->workerLogPaths();
        $command = sprintf(
            'cd %s && nohup %s > %s 2> %s < /dev/null & echo $!',
            escapeshellarg(base_path()),
            implode(' ', array_map('escapeshellarg', [PHP_BINARY, ...$this->workerArguments()])),
            escapeshellarg($stdout),
            escapeshellarg($stderr),
        );

        return Process::timeout(10)->run(['sh', '-c', $command]);
    }

    private function isWorkerProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        try {
            $result = PHP_OS_FAMILY === 'Windows'
                ? $this->windowsProcessCommandLine($pid)
                : Process::timeout(5)->run(['ps', '-p', (string) $pid, '-o', 'command=']);
        } catch (Throwable) {
            return false;
        }

        if (! $result->successful()) {
            return false;
        }

        $commandLine = $result->output();

        return str_contains($commandLine, 'queue:work')
            && str_contains($commandLine, '--name='.self::WORKER_NAME)
            && str_contains($commandLine, '--queue='.SubtitleQueue::workerQueueList())
            && str_contains($commandLine, '--tries='.(string) $this->tries());
    }

    private function windowsProcessCommandLine(int $pid): ProcessResult
    {
        $script = implode('; ', [
            '$process = Get-CimInstance Win32_Process -Filter '.$this->powershellString('ProcessId = '.$pid).' -ErrorAction SilentlyContinue',
            'if ($null -eq $process) { exit 1 }',
            'Write-Output $process.CommandLine',
        ]);

        return Process::timeout(5)->run([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            $script,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function workerArguments(): array
    {
        return [
            'artisan',
            'queue:work',
            SubtitleQueue::connection(),
            '--name='.self::WORKER_NAME,
            '--queue='.SubtitleQueue::workerQueueList(),
            '--tries='.(string) $this->tries(),
            '--timeout='.(string) $this->timeoutSeconds(),
            '--sleep='.(string) $this->sleepSeconds(),
            '--memory='.(string) $this->memoryMb(),
            '--max-time='.(string) $this->maxTimeSeconds(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function workerLogPaths(): array
    {
        $directory = storage_path('logs/subtitle-workers');

        File::ensureDirectoryExists($directory);

        $suffix = now()->format('YmdHis').'-'.bin2hex(random_bytes(3));

        return [
            $directory.DIRECTORY_SEPARATOR."worker-{$suffix}.log",
            $directory.DIRECTORY_SEPARATOR."worker-{$suffix}.err.log",
        ];
    }

    private function shouldAutoStart(): bool
    {
        if (! (bool) config('subtitles.queue.auto_start.enabled', true)) {
            return false;
        }

        if (app()->runningUnitTests() && ! (bool) config('subtitles.queue.auto_start.enabled_in_tests', false)) {
            return false;
        }

        return ! in_array($this->queueDriver(), ['sync', 'background'], true)
            && $this->targetWorkerCount() > 0;
    }

    private function targetWorkerCount(): int
    {
        $configured = (int) config('subtitles.queue.auto_start.worker_count', 0);

        if ($configured > 0) {
            return $configured;
        }

        if ($this->databaseDriver() === 'sqlite') {
            return 1;
        }

        return SubtitleTier::workerCount();
    }

    private function parsePid(string $output): ?int
    {
        if (preg_match('/\b([1-9][0-9]*)\b/', $output, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function powershellArray(array $values): string
    {
        return '@('.implode(', ', array_map($this->powershellString(...), $values)).')';
    }

    private function powershellString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function queueDriver(): string
    {
        $connection = SubtitleQueue::connection();

        return (string) config("queue.connections.{$connection}.driver", $connection);
    }

    private function databaseDriver(): string
    {
        $connection = (string) config('database.default');

        return (string) config("database.connections.{$connection}.driver", $connection);
    }

    private function lockSeconds(): int
    {
        return max(1, (int) config('subtitles.queue.auto_start.lock_seconds', 10));
    }

    private function maxTimeSeconds(): int
    {
        return max(60, (int) config('subtitles.queue.auto_start.max_time_seconds', 3600));
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('subtitles.queue.worker_timeout_seconds', 1200));
    }

    private function memoryMb(): int
    {
        return max(64, (int) config('subtitles.queue.auto_start.memory_mb', 256));
    }

    private function sleepSeconds(): int
    {
        return max(0, (int) config('subtitles.queue.auto_start.sleep_seconds', 1));
    }

    private function tries(): int
    {
        return max(0, (int) config('subtitles.queue.auto_start.tries', 0));
    }
}
