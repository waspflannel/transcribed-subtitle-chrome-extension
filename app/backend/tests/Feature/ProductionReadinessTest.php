<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_cerebras_readiness_does_not_require_an_openai_key(): void
    {
        $this->configureSafeProductionRuntime();
        $this->fakeHealthyConnectivity();
        config(['ai.default' => 'cerebras', 'ai.providers.openai.key' => '', 'ai.providers.cerebras.key' => 'test-key']);
        $this->assertSame(0, Artisan::call('ops:production-check', ['--json' => true]));
        $this->assertStringNotContainsString('test-key', Artisan::output());
    }

    public function test_production_readiness_check_passes_for_safe_beta_configuration(): void
    {
        $this->configureSafeProductionRuntime();
        $this->fakeHealthyConnectivity();

        $this->assertSame(0, Artisan::call('ops:production-check', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertTrue($payload['ok']);
        $this->assertSame('https://api.example.test', $payload['summary']['appUrl']);
        $this->assertTrue($payload['summary']['aiKeyConfigured']);
        $this->assertTrue($payload['summary']['databaseReachable']);
        $this->assertTrue($payload['summary']['queueRedisReachable']);
        $this->assertTrue($payload['summary']['concurrencyRedisReachable']);
        $this->assertSame('smtp', $payload['summary']['mailTransport']);
        $this->assertStringNotContainsString('sk-test-openai', $output);
        $this->assertStringNotContainsString('whsec_test', $output);
    }

    public function test_production_readiness_check_flags_unsafe_configuration_without_printing_secret_values(): void
    {
        $this->configureSafeProductionRuntime();
        config([
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'ai.providers.openai.key' => '',
            'queue.connections.redis.retry_after' => 60,
            'subtitles.audio_preparation.ffmpeg_binary' => '',
            'mail.default' => 'log',
            'mail.from.address' => 'hello@example.test',
            'marketing.support_email' => 'support@example.test',
            'marketing.chrome_extension_url' => '',
            'marketing.chrome_extension_release_version' => '0.0.0',
            'marketing.chrome_extension_api_host_permission' => 'http://localhost:8000/*',
        ]);
        $this->fakeHealthyConnectivity();

        $this->assertSame(1, Artisan::call('ops:production-check', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertFalse($payload['ok']);
        $this->assertContains('APP_DEBUG must be false.', $payload['problems']);
        $this->assertContains('APP_URL must use HTTPS.', $payload['problems']);
        $this->assertContains('The selected AI provider API key must be configured in the environment.', $payload['problems']);
        $this->assertContains('Queue retry_after must be greater than the subtitle worker timeout.', $payload['problems']);
        $this->assertContains('FFMPEG_BINARY must be configured.', $payload['problems']);
        $this->assertContains('MAIL_MAILER must use a configured production transport, not log or array.', $payload['problems']);
        $this->assertContains('MAIL_FROM_ADDRESS must be a non-placeholder production sender address.', $payload['problems']);
        $this->assertContains('SUPPORT_EMAIL must be a non-placeholder public support address.', $payload['problems']);
        $this->assertContains('CHROME_EXTENSION_URL must be a public HTTPS URL.', $payload['problems']);
        $this->assertContains('CHROME_EXTENSION_RELEASE_VERSION must be a real non-placeholder release version.', $payload['problems']);
        $this->assertContains('CHROME_EXTENSION_API_HOST_PERMISSION must exactly match the HTTPS APP_URL origin and must not use localhost.', $payload['problems']);
        $this->assertStringNotContainsString('sk-test-stripe', $output);
    }

    public function test_production_readiness_check_flags_unreachable_postgres_and_each_redis_role(): void
    {
        $this->configureSafeProductionRuntime();
        DB::shouldReceive('connection')
            ->once()
            ->with('pgsql')
            ->andThrow(new \RuntimeException('unavailable'));
        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andThrow(new \RuntimeException('unavailable'));
        Redis::shouldReceive('connection')
            ->once()
            ->with('cache')
            ->andThrow(new \RuntimeException('unavailable'));

        $this->assertSame(1, Artisan::call('ops:production-check', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true);

        $this->assertFalse($payload['summary']['databaseReachable']);
        $this->assertFalse($payload['summary']['queueRedisReachable']);
        $this->assertFalse($payload['summary']['concurrencyRedisReachable']);
        $this->assertContains('Postgres connectivity probe failed.', $payload['problems']);
        $this->assertContains('Subtitle queue Redis connectivity probe failed.', $payload['problems']);
        $this->assertContains('Subtitle concurrency Redis connectivity probe failed.', $payload['problems']);
    }

    public function test_queue_redis_failure_is_not_masked_by_a_healthy_concurrency_connection(): void
    {
        $this->configureSafeProductionRuntime();
        $this->fakeHealthyDatabase();
        $cacheRedis = Mockery::mock();
        $cacheRedis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')
            ->once()
            ->with('queue')
            ->andThrow(new \RuntimeException('queue unavailable'));
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($cacheRedis);

        $this->assertSame(1, Artisan::call('ops:production-check', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertFalse($payload['summary']['queueRedisReachable']);
        $this->assertTrue($payload['summary']['concurrencyRedisReachable']);
        $this->assertContains('Subtitle queue Redis connectivity probe failed.', $payload['problems']);
    }

    public function test_compound_mailer_fails_when_any_child_uses_a_non_production_transport(): void
    {
        $this->configureSafeProductionRuntime();
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover.transport' => 'failover',
            'mail.mailers.failover.mailers' => ['smtp', 'log'],
            'mail.mailers.log.transport' => 'log',
        ]);
        $this->fakeHealthyConnectivity();

        $this->assertSame(1, Artisan::call('ops:production-check', ['--json' => true]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertContains('MAIL_MAILER must use a configured production transport, not log or array.', $payload['problems']);
    }

    private function configureSafeProductionRuntime(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:configured-app-key',
            'app.url' => 'https://api.example.test/v1',
            'database.default' => 'pgsql',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'queue',
            'queue.connections.redis.retry_after' => 1260,
            'subtitles.queue.connection' => 'redis',
            'subtitles.queue.worker_timeout_seconds' => 1200,
            'subtitles.tiers.concurrency_cache_store' => 'subtitle_concurrency',
            'cache.stores.subtitle_concurrency.driver' => 'redis',
            'cache.stores.subtitle_concurrency.connection' => 'cache',
            'logging.default' => 'stack',
            'logging.channels.single.level' => 'info',
            'logging.channels.stderr.level' => 'info',
            'ai.providers.openai.key' => 'sk-test-openai',
            'ai.providers.eleven.key' => 'elevenlabs-test-secret',
            'billing.stripe.secret' => 'sk-test-stripe',
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.base.stripe_price_id' => 'price_base',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
            'subtitles.youtube.binary' => 'yt-dlp',
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.beta.example',
            'mail.mailers.smtp.username' => 'beta-user',
            'mail.mailers.smtp.password' => 'beta-password',
            'mail.from.address' => 'support@beta.example',
            'marketing.support_email' => 'support@beta.example',
            'marketing.chrome_extension_url' => 'https://chromewebstore.google.com/detail/example-extension/abcdefghijklmnop',
            'marketing.chrome_extension_release_version' => '1.0.0',
            'marketing.chrome_extension_api_host_permission' => 'https://api.example.test/*',
        ]);
    }

    private function fakeHealthyConnectivity(): void
    {
        $this->fakeHealthyDatabase();
        $queueRedis = Mockery::mock();
        $queueRedis->shouldReceive('ping')->once()->andReturn('PONG');
        $concurrencyRedis = Mockery::mock();
        $concurrencyRedis->shouldReceive('ping')->once()->andReturn('PONG');

        Redis::shouldReceive('connection')->once()->with('queue')->andReturn($queueRedis);
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($concurrencyRedis);
    }

    private function fakeHealthyDatabase(): void
    {
        $database = Mockery::mock();
        $database->shouldReceive('select')->once()->with('select 1')->andReturn([]);
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($database);
    }
}
