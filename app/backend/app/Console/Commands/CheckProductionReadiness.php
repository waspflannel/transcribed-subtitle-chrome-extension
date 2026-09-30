<?php

namespace App\Console\Commands;

use App\Services\InstanceSettings;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

#[Signature('ops:production-check {--target=production : Expected APP_ENV value} {--json : Output machine-readable JSON}')]
#[Description('Check private instance runtime settings and connectivity.')]
class CheckProductionReadiness extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $target = trim((string) $this->option('target')) ?: 'production';
        $summary = $this->summary();
        $checks = $this->checks($summary, $target);
        $problems = collect($checks)
            ->where('ok', false)
            ->pluck('message')
            ->values()
            ->all();

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $problems === [],
                'target' => $target,
                'summary' => $summary,
                'checks' => $checks,
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['setting', 'value'], collect($summary)
            ->map(fn (mixed $value, string $key): array => [$key, $this->displayValue($value)])
            ->values()
            ->all());

        $this->table(['check', 'status', 'message'], collect($checks)
            ->map(fn (array $check): array => [
                $check['name'],
                $check['ok'] ? 'ok' : 'failed',
                $check['message'],
            ])
            ->values()
            ->all());

        if ($problems === []) {
            $this->components->info('Production readiness checks passed.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $databaseConnection = (string) config('database.default');
        $databaseReachable = $this->databaseReachable($databaseConnection);
        if ($databaseReachable) {
            app(InstanceSettings::class)->apply();
        }
        $queueConnection = SubtitleQueue::connection();
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", $queueConnection);
        $queueRedisConnection = (string) config("queue.connections.{$queueConnection}.connection", 'default');
        $concurrencyCacheStore = SubtitleQueue::concurrencyCacheStore();
        $concurrencyRedisConnection = (string) config("cache.stores.{$concurrencyCacheStore}.connection", 'cache');
        $workerTimeoutSeconds = (int) config('subtitles.queue.worker_timeout_seconds', 0);
        $retryAfterSeconds = (int) config("queue.connections.{$queueConnection}.retry_after", 0);

        return [
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'appKeyConfigured' => $this->configured(config('app.key')),
            'appUrl' => $this->safeUrl((string) config('app.url')),
            'appUrlIsHttps' => Str::startsWith((string) config('app.url'), 'https://'),
            'appUrlIsLocal' => in_array(parse_url((string) config('app.url'), PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true),
            'databaseConnection' => $databaseConnection,
            'databaseDriver' => (string) config("database.connections.{$databaseConnection}.driver", $databaseConnection),
            'databaseReachable' => $databaseReachable,
            'queueConnection' => $queueConnection,
            'queueDriver' => $queueDriver,
            'queueRedisConnection' => $queueRedisConnection,
            'queueRedisReachable' => $queueDriver === 'redis' && $this->redisReachable($queueRedisConnection),
            'queueRetryAfterSeconds' => $retryAfterSeconds,
            'workerTimeoutSeconds' => $workerTimeoutSeconds,
            'workerRetryAfterExceedsTimeout' => $retryAfterSeconds > $workerTimeoutSeconds,
            'configuredWorkerCount' => SubtitleQueue::workerCount(),
            'workerGroups' => collect(SubtitleQueue::workerGroups())
                ->map(fn (array $group): array => [
                    'name' => $group['name'],
                    'queueFamily' => $group['queue_family'],
                    'queues' => $group['queues'],
                    'workerCount' => $group['worker_count'],
                ])
                ->values()
                ->all(),
            'concurrencyCacheStore' => $concurrencyCacheStore,
            'concurrencyCacheDriver' => (string) config("cache.stores.{$concurrencyCacheStore}.driver", 'unconfigured'),
            'concurrencyRedisConnection' => $concurrencyRedisConnection,
            'concurrencyRedisReachable' => $this->redisReachable($concurrencyRedisConnection),
            'cacheStore' => (string) config('cache.default'),
            'logChannel' => (string) config('logging.default'),
            'singleLogLevel' => (string) config('logging.channels.single.level', ''),
            'stderrLogLevel' => (string) config('logging.channels.stderr.level', ''),
            'aiKeyConfigured' => $this->configured(config('ai.providers.'.config('ai.default').'.key')),
            'elevenLabsKeyConfigured' => $this->configured(config('ai.providers.eleven.key')),
            'youtubeAudioBinaryConfigured' => $this->configured(config('subtitles.youtube.binary')),
            'ffmpegBinaryConfigured' => $this->configured(config('subtitles.audio_preparation.ffmpeg_binary')),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, array{name: string, ok: bool, message: string}>
     */
    private function checks(array $summary, string $target): array
    {
        return [
            $this->check('app.environment', $summary['environment'] === $target, "APP_ENV is {$summary['environment']}; expected {$target}."),
            $this->check('app.debug', $summary['debug'] === false, 'APP_DEBUG must be false.'),
            $this->check('app.key', $summary['appKeyConfigured'] === true, 'APP_KEY must be configured.'),
            $this->check('app.url', $summary['appUrlIsHttps'] || $summary['appUrlIsLocal'], 'Remote APP_URL must use HTTPS; local loopback can use HTTP.'),
            $this->check('database.pgsql', $summary['databaseDriver'] === 'pgsql', "Database driver is {$summary['databaseDriver']}; expected pgsql."),
            $this->check('database.connectivity', $summary['databaseReachable'] === true, 'Postgres connectivity probe failed.'),
            $this->check('queue.redis', $summary['queueDriver'] === 'redis', "Subtitle queue driver is {$summary['queueDriver']}; expected redis."),
            $this->check('queue.connectivity', $summary['queueRedisReachable'] === true, 'Subtitle queue Redis connectivity probe failed.'),
            $this->check('queue.retry_after', $summary['workerRetryAfterExceedsTimeout'] === true, 'Queue retry_after must be greater than the subtitle worker timeout.'),
            $this->check('workers.configured', (int) $summary['configuredWorkerCount'] > 0, 'At least one subtitle worker must be configured.'),
            $this->check('concurrency.redis', $summary['concurrencyCacheDriver'] === 'redis', "Subtitle concurrency cache driver is {$summary['concurrencyCacheDriver']}; expected redis."),
            $this->check('concurrency.connectivity', $summary['concurrencyRedisReachable'] === true, 'Subtitle concurrency Redis connectivity probe failed.'),
            $this->check('logging.enabled', $summary['logChannel'] !== 'null', 'LOG_CHANNEL must not be null.'),
            $this->check('logging.level', $this->logLevelsAreProductionSafe($summary), 'Production log levels should not be debug.'),
            $this->check('youtube.binary', $summary['youtubeAudioBinaryConfigured'] === true, 'YOUTUBE_AUDIO_BINARY must be configured.'),
            $this->check('audio_preparation.ffmpeg_binary', $summary['ffmpegBinaryConfigured'] === true, 'FFMPEG_BINARY must be configured.'),
        ];
    }

    private function databaseReachable(string $connection): bool
    {
        try {
            DB::connection($connection)->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisReachable(string $connection): bool
    {
        try {
            Redis::connection($connection)->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{name: string, ok: bool, message: string}
     */
    private function check(string $name, bool $ok, string $message): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'message' => $ok ? 'ok' : $message,
        ];
    }

    private function configured(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function safeUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function logLevelsAreProductionSafe(array $summary): bool
    {
        $levels = [
            strtolower((string) $summary['singleLogLevel']),
            strtolower((string) $summary['stderrLogLevel']),
        ];

        return ! in_array('debug', $levels, true);
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }
}
