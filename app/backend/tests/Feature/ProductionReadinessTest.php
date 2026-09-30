<?php

namespace Tests\Feature;

use App\Services\InstanceSettings;
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

    public function test_readiness_allows_localhost_without_provider_keys_or_accounts(): void
    {
        $this->configureSafeProductionRuntime();
        $this->fakeHealthyConnectivity();
        config(['app.url' => 'http://localhost:8000', 'ai.providers.openai.key' => null, 'ai.providers.eleven.key' => null]);

        $this->assertSame(0, Artisan::call('ops:production-check', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertTrue($payload['ok']);
        $this->assertSame('http://localhost:8000', $payload['summary']['appUrl']);
        $this->assertFalse($payload['summary']['aiKeyConfigured']);
        $this->assertTrue($payload['summary']['databaseReachable']);
        $this->assertTrue($payload['summary']['queueRedisReachable']);
        $this->assertTrue($payload['summary']['concurrencyRedisReachable']);
        $this->assertStringNotContainsString('sk-test-openai', $output);
        $this->assertStringNotContainsString('whsec_test', $output);
    }

    public function test_production_readiness_check_flags_unsafe_configuration_without_printing_secret_values(): void
    {
        $this->configureSafeProductionRuntime();
        config([
            'app.debug' => true,
            'app.url' => 'http://remote.example.test',
            'ai.providers.openai.key' => '',
            'queue.connections.redis.retry_after' => 60,
            'subtitles.audio_preparation.ffmpeg_binary' => '',
        ]);
        $this->fakeHealthyConnectivity();

        $this->assertSame(1, Artisan::call('ops:production-check', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertFalse($payload['ok']);
        $this->assertContains('APP_DEBUG must be false.', $payload['problems']);
        $this->assertContains('Remote APP_URL must use HTTPS; local loopback can use HTTP.', $payload['problems']);
        $this->assertContains('Queue retry_after must be greater than the subtitle worker timeout.', $payload['problems']);
        $this->assertContains('FFMPEG_BINARY must be configured.', $payload['problems']);
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
            'subtitles.providers.concurrency_cache_store' => 'subtitle_concurrency',
            'cache.stores.subtitle_concurrency.driver' => 'redis',
            'cache.stores.subtitle_concurrency.connection' => 'cache',
            'logging.default' => 'stack',
            'logging.channels.single.level' => 'info',
            'logging.channels.stderr.level' => 'info',
            'ai.providers.openai.key' => 'sk-test-openai',
            'ai.providers.eleven.key' => 'elevenlabs-test-secret',
            'subtitles.youtube.binary' => 'yt-dlp',
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg',
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
        $this->mock(InstanceSettings::class)->shouldReceive('apply')->once();
        $database = Mockery::mock();
        $database->shouldReceive('select')->once()->with('select 1')->andReturn([]);
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($database);
    }
}
