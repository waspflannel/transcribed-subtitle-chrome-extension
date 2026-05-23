<?php

namespace App\Console\Commands;

use App\Services\Subtitles\SubtitleQueue;
use App\Services\Subtitles\SubtitleTier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subtitles:runtime-check {--json : Output machine-readable JSON} {--strict : Enforce runtime requirements even when APP_ENV=testing}')]
#[Description('Check whether the backend runtime is using Postgres and Redis for subtitle processing.')]
class CheckSubtitleRuntime extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $databaseConnection = (string) config('database.default');
        $databaseDriver = (string) config("database.connections.{$databaseConnection}.driver", $databaseConnection);
        $queueConnection = SubtitleQueue::connection();
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", $queueConnection);
        $concurrencyCacheStore = SubtitleTier::concurrencyCacheStore();
        $concurrencyCacheDriver = (string) config("cache.stores.{$concurrencyCacheStore}.driver", 'unconfigured');
        $isTesting = app()->environment('testing');
        $enforceRuntime = $this->option('strict') || ! $isTesting;

        $summary = [
            'environment' => app()->environment(),
            'phpBinary' => PHP_BINARY,
            'pdoPgsqlLoaded' => extension_loaded('pdo_pgsql'),
            'databaseConnection' => $databaseConnection,
            'databaseDriver' => $databaseDriver,
            'queueDefault' => (string) config('queue.default'),
            'subtitleQueueConnection' => $queueConnection,
            'subtitleQueueDriver' => $queueDriver,
            'subtitleQueueName' => SubtitleQueue::generationName(),
            'subtitleGenerationQueues' => SubtitleQueue::generationNames(),
            'subtitleBatchQueues' => SubtitleQueue::batchNames(),
            'subtitleWorkerQueues' => SubtitleQueue::workerQueueList(),
            'subtitleWorkerGroups' => SubtitleQueue::workerGroups(),
            'subtitleAutoStartWorkers' => (bool) config('subtitles.queue.auto_start.enabled'),
            'subtitleAutoWorkerCount' => (int) config('subtitles.queue.auto_start.worker_count', 0),
            'subtitleAutoWorkerTries' => (int) config('subtitles.queue.auto_start.tries', 0),
            'subtitleConfiguredWorkerCount' => SubtitleTier::workerCount(),
            'subtitleConcurrencyCacheStore' => $concurrencyCacheStore,
            'subtitleConcurrencyCacheDriver' => $concurrencyCacheDriver,
            'subtitleConcurrencyRedisConnection' => (string) config("cache.stores.{$concurrencyCacheStore}.connection", ''),
            'subtitleConcurrencyRedisLockConnection' => (string) config("cache.stores.{$concurrencyCacheStore}.lock_connection", ''),
            'redisClient' => (string) config('database.redis.client'),
            'cacheStore' => (string) config('cache.default'),
        ];

        $problems = [];

        if ($enforceRuntime && ! extension_loaded('pdo_pgsql')) {
            $problems[] = 'PHP extension pdo_pgsql is not loaded.';
        }

        if ($enforceRuntime && $databaseDriver !== 'pgsql') {
            $problems[] = "Runtime database driver is {$databaseDriver}; expected pgsql.";
        }

        if ($enforceRuntime && $queueDriver !== 'redis') {
            $problems[] = "Subtitle queue driver is {$queueDriver}; expected redis.";
        }

        if ($enforceRuntime && $queueDriver === 'redis' && $concurrencyCacheDriver !== 'redis') {
            $problems[] = "Subtitle concurrency cache driver is {$concurrencyCacheDriver}; expected redis.";
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $problems === [],
                'summary' => $summary,
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['setting', 'value'], collect($summary)
            ->map(fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'true' : 'false') : $value])
            ->values()
            ->all());

        if ($problems === []) {
            $this->components->info('Subtitle runtime profile is Postgres + Redis ready.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
