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
                    'worker_groups' => $this->workerGroups(),
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
                'worker_groups' => $this->workerGroups(),
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
            'queueConnection' => SubtitleQueue::connection(),
            'workerGroups' => $this->workerGroups(),
            'maxTimeSeconds' => $this->maxTimeSeconds(),
            'timeoutSeconds' => $this->timeoutSeconds(),
            'memoryMb' => $this->memoryMb(),
            'tries' => $this->tries(),
        ];
    }

    private function syncWorkers(): void
    {
        $workerGroups = $this->workerGroups();
        $runningWorkers = $this->runningWorkers($this->storedWorkers());
        $startedWorkers = [];

        foreach ($workerGroups as $workerGroup) {
            $runningGroupWorkers = array_values(array_filter(
                $runningWorkers,
                fn (array $worker): bool => ($worker['worker_group'] ?? null) === $workerGroup['name'],
            ));
            $missingWorkerCount = max(0, $workerGroup['worker_count'] - count($runningGroupWorkers));

            for ($index = 0; $index < $missingWorkerCount; $index++) {
                $startedWorker = $this->startWorker($workerGroup);

                if ($startedWorker !== null) {
                    $startedWorkers[] = $startedWorker;
                }
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
            'worker_groups' => $workerGroups,
            'target_worker_count' => $this->targetWorkerCount(),
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
                && $this->isWorkerProcessRunning($worker),
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
     * @param  array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}  $workerGroup
     * @return array<string, mixed>|null
     */
    private function startWorker(array $workerGroup): ?array
    {
        $result = PHP_OS_FAMILY === 'Windows'
            ? $this->startWindowsWorker($workerGroup)
            : $this->startUnixWorker($workerGroup);

        $pid = $this->parsePid($result->output());

        if (! $result->successful() || $pid === null) {
            Log::warning('backend.subtitle_worker_auto_start_failed', [
                'reason' => 'process_start_failed',
                'exit_code' => $result->exitCode(),
                'queue_connection' => SubtitleQueue::connection(),
                'worker_group' => $workerGroup['name'],
                'queue_family' => $workerGroup['queue_family'],
                'queues' => implode(',', $workerGroup['queues']),
            ]);

            return null;
        }

        $worker = [
            'pid' => $pid,
            'started_at' => now()->toJSON(),
            'queue_connection' => SubtitleQueue::connection(),
            'worker_group' => $workerGroup['name'],
            'queue_family' => $workerGroup['queue_family'],
            'queues' => implode(',', $workerGroup['queues']),
            'worker_name' => $this->workerName($workerGroup),
            'max_time_seconds' => $this->maxTimeSeconds(),
            'tries' => $this->tries(),
        ];

        Log::info('backend.subtitle_worker_auto_started', $worker);

        return $worker;
    }

    /**
     * @param  array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}  $workerGroup
     */
    private function startWindowsWorker(array $workerGroup): ProcessResult
    {
        [$stdout, $stderr] = $this->workerLogPaths($workerGroup);
        $arguments = $this->powershellArray($this->workerArguments($workerGroup));
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

    /**
     * @param  array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}  $workerGroup
     */
    private function startUnixWorker(array $workerGroup): ProcessResult
    {
        [$stdout, $stderr] = $this->workerLogPaths($workerGroup);
        $command = sprintf(
            'cd %s && nohup %s > %s 2> %s < /dev/null & echo $!',
            escapeshellarg(base_path()),
            implode(' ', array_map('escapeshellarg', [PHP_BINARY, ...$this->workerArguments($workerGroup)])),
            escapeshellarg($stdout),
            escapeshellarg($stderr),
        );

        return Process::timeout(10)->run(['sh', '-c', $command]);
    }

    /**
     * @param  array<string, mixed>  $worker
     */
    private function isWorkerProcessRunning(array $worker): bool
    {
        $pid = $worker['pid'] ?? null;

        if (! is_int($pid)) {
            return false;
        }

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
        $workerName = is_string($worker['worker_name'] ?? null) && $worker['worker_name'] !== ''
            ? $worker['worker_name']
            : self::WORKER_NAME;
        $queues = is_string($worker['queues'] ?? null) && $worker['queues'] !== ''
            ? $worker['queues']
            : SubtitleQueue::workerQueueList();

        return str_contains($commandLine, 'queue:work')
            && str_contains($commandLine, '--name='.$workerName)
            && str_contains($commandLine, '--queue='.$queues)
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
     * @param  array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}  $workerGroup
     * @return array<int, string>
     */
    private function workerArguments(array $workerGroup): array
    {
        return [
            'artisan',
            'queue:work',
            SubtitleQueue::connection(),
            '--name='.$this->workerName($workerGroup),
            '--queue='.implode(',', $workerGroup['queues']),
            '--tries='.(string) $this->tries(),
            '--timeout='.(string) $this->timeoutSeconds(),
            '--sleep='.(string) $this->sleepSeconds(),
            '--memory='.(string) $this->memoryMb(),
            '--max-time='.(string) $this->maxTimeSeconds(),
        ];
    }

    /**
     * @param  array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}  $workerGroup
     * @return array{0: string, 1: string}
     */
    private function workerLogPaths(array $workerGroup): array
    {
        $directory = storage_path('logs/subtitle-workers');

        File::ensureDirectoryExists($directory);

        $suffix = now()->format('YmdHis').'-'.bin2hex(random_bytes(3));
        $groupName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $workerGroup['name']) ?: 'worker';

        return [
            $directory.DIRECTORY_SEPARATOR."{$groupName}-{$suffix}.log",
            $directory.DIRECTORY_SEPARATOR."{$groupName}-{$suffix}.err.log",
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
        return array_sum(array_map(
            fn (array $workerGroup): int => $workerGroup['worker_count'],
            $this->workerGroups(),
        ));
    }

    /**
     * @return array<int, array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}>
     */
    private function workerGroups(): array
    {
        $configured = (int) config('subtitles.queue.auto_start.worker_count', 0);

        if ($configured > 0) {
            return [[
                'name' => 'priority-override',
                'queue_family' => 'all',
                'queues' => SubtitleQueue::workerQueues(),
                'worker_count' => $configured,
            ]];
        }

        return SubtitleQueue::workerGroups();
    }

    /**
     * @param  array{name: string, queue_family: string, queues: array<int, string>, worker_count: int}  $workerGroup
     */
    private function workerName(array $workerGroup): string
    {
        return self::WORKER_NAME.'-'.$workerGroup['name'];
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
