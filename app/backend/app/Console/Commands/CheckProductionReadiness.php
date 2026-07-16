<?php

namespace App\Console\Commands;

use App\Services\Subtitles\SubtitleQueue;
use App\Services\Subtitles\SubtitleTier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

#[Signature('ops:production-check {--target=production : Expected APP_ENV value} {--json : Output machine-readable JSON}')]
#[Description('Check production/staging safety settings for paid beta operations.')]
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
        $queueConnection = SubtitleQueue::connection();
        $queueDriver = (string) config("queue.connections.{$queueConnection}.driver", $queueConnection);
        $queueRedisConnection = (string) config("queue.connections.{$queueConnection}.connection", 'default');
        $concurrencyCacheStore = SubtitleTier::concurrencyCacheStore();
        $concurrencyRedisConnection = (string) config("cache.stores.{$concurrencyCacheStore}.connection", 'cache');
        $workerTimeoutSeconds = (int) config('subtitles.queue.worker_timeout_seconds', 0);
        $retryAfterSeconds = (int) config("queue.connections.{$queueConnection}.retry_after", 0);

        return [
            'environment' => (string) config('app.env'),
            'debug' => (bool) config('app.debug'),
            'appKeyConfigured' => $this->configured(config('app.key')),
            'appUrl' => $this->safeUrl((string) config('app.url')),
            'appUrlIsHttps' => Str::startsWith((string) config('app.url'), 'https://'),
            'databaseConnection' => $databaseConnection,
            'databaseDriver' => (string) config("database.connections.{$databaseConnection}.driver", $databaseConnection),
            'databaseReachable' => $this->databaseReachable($databaseConnection),
            'queueConnection' => $queueConnection,
            'queueDriver' => $queueDriver,
            'queueRedisConnection' => $queueRedisConnection,
            'queueRedisReachable' => $queueDriver === 'redis' && $this->redisReachable($queueRedisConnection),
            'queueRetryAfterSeconds' => $retryAfterSeconds,
            'workerTimeoutSeconds' => $workerTimeoutSeconds,
            'workerRetryAfterExceedsTimeout' => $retryAfterSeconds > $workerTimeoutSeconds,
            'subtitleAutoStartWorkers' => (bool) config('subtitles.queue.auto_start.enabled'),
            'configuredWorkerCount' => SubtitleTier::workerCount(),
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
            'openaiKeyConfigured' => $this->configured(config('ai.providers.openai.key')),
            'elevenLabsKeyConfigured' => $this->configured(config('ai.providers.eleven.key')),
            'stripeSecretConfigured' => $this->configured(config('billing.stripe.secret')),
            'stripeWebhookSecretConfigured' => $this->configured(config('billing.stripe.webhook_secret')),
            'stripePriceIdsConfigured' => collect((array) config('billing.plans'))
                ->every(fn (array $plan): bool => $this->configured($plan['stripe_price_id'] ?? null)),
            'billingTestPlanSwitcherEnabled' => (bool) config('billing.testing_plan_switcher.enabled'),
            'youtubeAudioBinaryConfigured' => $this->configured(config('subtitles.youtube.binary')),
            'ffmpegBinaryConfigured' => $this->configured(config('subtitles.audio_preparation.ffmpeg_binary')),
            'audioIsolationEnabled' => (bool) config('subtitles.audio_preparation.voice_isolation.enabled'),
            'audioIsolationFailOpen' => (bool) config('subtitles.audio_preparation.voice_isolation.fail_open'),
            'mailMailer' => (string) config('mail.default'),
            'mailTransport' => $this->mailTransport(),
            'mailTransportConfigured' => $this->mailTransportConfigured(),
            'mailFromAddress' => $this->safeEmail((string) config('mail.from.address')),
            'mailFromAddressIsPlaceholder' => $this->mailFromAddressIsPlaceholder(),
            'supportEmail' => $this->safeEmail((string) config('marketing.support_email')),
            'supportEmailIsPlaceholder' => $this->emailIsPlaceholder((string) config('marketing.support_email')),
            'chromeExtensionUrl' => $this->safeUrl((string) config('marketing.chrome_extension_url')),
            'chromeExtensionUrlConfigured' => $this->validPublicHttpsUrl((string) config('marketing.chrome_extension_url')),
            'chromeExtensionReleaseVersion' => (string) config('marketing.chrome_extension_release_version'),
            'chromeExtensionReleaseVersionConfigured' => $this->validExtensionReleaseVersion((string) config('marketing.chrome_extension_release_version')),
            'chromeExtensionApiHostPermission' => (string) config('marketing.chrome_extension_api_host_permission'),
            'expectedExtensionApiHostPermission' => $this->expectedExtensionApiHostPermission(),
            'chromeExtensionApiHostPermissionMatches' => $this->extensionApiHostPermissionMatches(),
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
            $this->check('app.url', $summary['appUrlIsHttps'] === true, 'APP_URL must use HTTPS.'),
            $this->check('database.pgsql', $summary['databaseDriver'] === 'pgsql', "Database driver is {$summary['databaseDriver']}; expected pgsql."),
            $this->check('database.connectivity', $summary['databaseReachable'] === true, 'Postgres connectivity probe failed.'),
            $this->check('queue.redis', $summary['queueDriver'] === 'redis', "Subtitle queue driver is {$summary['queueDriver']}; expected redis."),
            $this->check('queue.connectivity', $summary['queueRedisReachable'] === true, 'Subtitle queue Redis connectivity probe failed.'),
            $this->check('queue.retry_after', $summary['workerRetryAfterExceedsTimeout'] === true, 'Queue retry_after must be greater than the subtitle worker timeout.'),
            $this->check('workers.supervised', $summary['subtitleAutoStartWorkers'] === false, 'SUBTITLE_AUTO_START_WORKERS must be false so production uses supervised workers.'),
            $this->check('workers.configured', (int) $summary['configuredWorkerCount'] > 0, 'At least one subtitle worker must be configured.'),
            $this->check('concurrency.redis', $summary['concurrencyCacheDriver'] === 'redis', "Subtitle concurrency cache driver is {$summary['concurrencyCacheDriver']}; expected redis."),
            $this->check('concurrency.connectivity', $summary['concurrencyRedisReachable'] === true, 'Subtitle concurrency Redis connectivity probe failed.'),
            $this->check('logging.enabled', $summary['logChannel'] !== 'null', 'LOG_CHANNEL must not be null.'),
            $this->check('logging.level', $this->logLevelsAreProductionSafe($summary), 'Production log levels should not be debug.'),
            $this->check('providers.openai', $summary['openaiKeyConfigured'] === true, 'OPENAI_API_KEY must be configured in the environment.'),
            $this->check('providers.elevenlabs', $summary['elevenLabsKeyConfigured'] === true, 'ELEVENLABS_API_KEY must be configured in the environment.'),
            $this->check('billing.stripe_secret', $summary['stripeSecretConfigured'] === true, 'STRIPE_SECRET must be configured in the environment.'),
            $this->check('billing.webhook_secret', $summary['stripeWebhookSecretConfigured'] === true, 'STRIPE_WEBHOOK_SECRET must be configured in the environment.'),
            $this->check('billing.price_ids', $summary['stripePriceIdsConfigured'] === true, 'All Stripe plan price IDs must be configured.'),
            $this->check('billing.test_switcher', $summary['billingTestPlanSwitcherEnabled'] === false, 'BILLING_TEST_PLAN_SWITCHER must be false.'),
            $this->check('youtube.binary', $summary['youtubeAudioBinaryConfigured'] === true, 'YOUTUBE_AUDIO_BINARY must be configured.'),
            $this->check('audio_preparation.ffmpeg_binary', $summary['ffmpegBinaryConfigured'] === true, 'FFMPEG_BINARY must be configured.'),
            // Voice isolation is optional (off by default until A/B evidence
            // proves its cost; TD-014), but when enabled it must fail open.
            $this->check('audio_preparation.voice_isolation_fail_open', $summary['audioIsolationEnabled'] === false || $summary['audioIsolationFailOpen'] === true, 'ELEVENLABS_AUDIO_ISOLATION_FAIL_OPEN must be true until staging evidence supports fail-closed behavior.'),
            $this->check('mail.transport', $summary['mailTransportConfigured'] === true, 'MAIL_MAILER must use a configured production transport, not log or array.'),
            $this->check('mail.sender', $summary['mailFromAddress'] !== '' && $summary['mailFromAddressIsPlaceholder'] === false, 'MAIL_FROM_ADDRESS must be a non-placeholder production sender address.'),
            $this->check('release.support_email', $summary['supportEmail'] !== '' && $summary['supportEmailIsPlaceholder'] === false, 'SUPPORT_EMAIL must be a non-placeholder public support address.'),
            $this->check('release.chrome_extension_url', $summary['chromeExtensionUrlConfigured'] === true, 'CHROME_EXTENSION_URL must be a public HTTPS URL.'),
            $this->check('release.chrome_extension_version', $summary['chromeExtensionReleaseVersionConfigured'] === true, 'CHROME_EXTENSION_RELEASE_VERSION must be a real non-placeholder release version.'),
            $this->check('release.chrome_extension_host_permission', $summary['chromeExtensionApiHostPermissionMatches'] === true, 'CHROME_EXTENSION_API_HOST_PERMISSION must exactly match the HTTPS APP_URL origin and must not use localhost.'),
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

    private function mailTransport(): string
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return is_string($transport) ? $transport : '';
    }

    private function mailTransportConfigured(): bool
    {
        return $this->mailerConfigured((string) config('mail.default'));
    }

    /**
     * @param  array<int, string>  $visited
     */
    private function mailerConfigured(string $mailer, array $visited = []): bool
    {
        if ($mailer === '' || in_array($mailer, $visited, true)) {
            return false;
        }

        $transport = config("mail.mailers.{$mailer}.transport");

        if (! is_string($transport) || $transport === '' || in_array($transport, ['array', 'log'], true)) {
            return false;
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = config("mail.mailers.{$mailer}.mailers");

            return is_array($mailers)
                && $mailers !== []
                && collect($mailers)->every(fn (mixed $child): bool => is_string($child)
                    && $this->mailerConfigured($child, [...$visited, $mailer]));
        }

        if ($transport !== 'smtp') {
            return true;
        }

        $host = strtolower(trim((string) config("mail.mailers.{$mailer}.host")));

        return ! in_array($host, ['', '127.0.0.1', 'localhost'], true)
            && $this->configured(config("mail.mailers.{$mailer}.username"))
            && $this->configured(config("mail.mailers.{$mailer}.password"));
    }

    private function safeEmail(string $email): string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function mailFromAddressIsPlaceholder(): bool
    {
        return $this->emailIsPlaceholder((string) config('mail.from.address'));
    }

    private function emailIsPlaceholder(string $value): bool
    {
        $email = strtolower($this->safeEmail($value));

        return $email === ''
            || str_ends_with($email, '@example.com')
            || str_ends_with($email, '.test')
            || str_contains($email, '@example.');
    }

    private function validPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! in_array(strtolower((string) $parts['host']), ['127.0.0.1', 'localhost'], true);
    }

    private function validExtensionReleaseVersion(string $version): bool
    {
        return $version !== '0.0.0'
            && preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version) === 1;
    }

    private function expectedExtensionApiHostPermission(): string
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).$port.'/*';
    }

    private function extensionApiHostPermissionMatches(): bool
    {
        $permission = strtolower(trim((string) config('marketing.chrome_extension_api_host_permission')));
        $expected = $this->expectedExtensionApiHostPermission();

        return $permission !== ''
            && $expected !== ''
            && str_starts_with($expected, 'https://')
            && ! str_contains($expected, '://localhost')
            && ! str_contains($expected, '://127.0.0.1')
            && $permission === $expected;
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
