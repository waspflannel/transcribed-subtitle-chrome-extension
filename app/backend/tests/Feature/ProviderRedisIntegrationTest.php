<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Subtitles\ProviderAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProviderRedisIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $port = (int) getenv('SUBTITLE_TEST_REDIS_PORT');
        if ($port === 0) {
            $this->markTestSkipped('Set SUBTITLE_TEST_REDIS_PORT to a dedicated disposable Redis instance.');
        }
        $this->prefix = 'provider-review-'.bin2hex(random_bytes(16)).':';
        (require __DIR__.'/../Fixtures/provider-redis.php')($port, $this->prefix);
    }

    public function test_native_provider_permits_are_shared_across_processes_and_release(): void
    {
        $script = <<<'PHP'
require 'tests/bootstrap.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
(require 'tests/Fixtures/provider-redis.php')((int) $argv[1], $argv[2]);
$cache = Illuminate\Support\Facades\Cache::store('provider_review');
$job = App\Models\SubtitleJob::factory()->create();
app(App\Services\Subtitles\ProviderAdmission::class)->run('openai', $job, function () use ($cache) {
    $cache->put('started', true, 20);
    $deadline = microtime(true) + 10;
    while (!$cache->get('release') && microtime(true) < $deadline) { usleep(20000); }
    if (!$cache->get('release')) { throw new RuntimeException('Parent did not release test worker.'); }
});
PHP;
        $worker = new Process([PHP_BINARY, '-r', $script, getenv('SUBTITLE_TEST_REDIS_PORT'), $this->prefix], base_path());
        $worker->setTimeout(15)->start();
        $cache = Cache::store('provider_review');
        try {
            $deadline = microtime(true) + 5;
            while (! $cache->get('started') && $worker->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertTrue($cache->get('started') === true, $worker->getErrorOutput());
            foreach (['openai'] as $provider) {
                try {
                    app(ProviderAdmission::class)->run($provider, SubtitleJob::factory()->create(), fn () => $this->fail('Concurrent process bypassed capacity.'));
                    $this->fail('Expected admission rejection.');
                } catch (SubtitleProcessingException $exception) {
                    $this->assertSame('provider_admission', $exception->context['reason']);
                }
            }
            $cache->put('release', true, 20);
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $this->assertSame('released', app(ProviderAdmission::class)->run('openai', SubtitleJob::factory()->create(), fn () => 'released'));
        } finally {
            $cache->put('release', true, 20);
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    public function test_native_redis_queue_counts_include_ready_delayed_and_reserved_work(): void
    {
        $queue = new RedisQueue(app('redis'), 'review', 'provider_review');
        $queue->setContainer(app());
        $connection = Redis::connection('provider_review');
        $connection->rpush('queues:review', '{}', '{}');
        $connection->zadd('queues:review:delayed', now()->addMinute()->timestamp, 'delayed-payload');
        $connection->zadd('queues:review:reserved', now()->addMinute()->timestamp, 'reserved-payload');
        try {
            $this->assertSame(4, $queue->size('review'));
            $this->assertSame(2, $queue->pendingSize('review'));
            $this->assertSame(1, $queue->delayedSize('review'));
            $this->assertSame(1, $queue->reservedSize('review'));
        } finally {
            $connection->del('queues:review', 'queues:review:delayed', 'queues:review:reserved');
        }
    }
}
