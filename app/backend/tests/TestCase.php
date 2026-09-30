<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        require __DIR__.'/bootstrap.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $expected = [
                'app.env' => 'testing',
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'cache.default' => 'array',
                'subtitles.providers.concurrency_cache_store' => 'array',
                'session.driver' => 'array',
                'mail.default' => 'array',
                'queue.default' => 'sync',
                'subtitles.queue.connection' => 'sync',
                'filesystems.default' => 'local',
                'filesystems.disks.local.root' => $app->storagePath('app/private'),
            ];
            foreach ($expected as $key => $value) {
                if ($app['config']->get($key) !== $value) {
                    throw new \RuntimeException('Unsafe test configuration: '.$key);
                }
            }
            if ($app->storagePath() !== SUBTITLE_TEST_STORAGE || $app['config']->get('database.connections.sqlite.url')) {
                throw new \RuntimeException('Unsafe test database URL.');
            }
        });
        $app->make(Kernel::class)->bootstrap();
        Http::preventStrayRequests();

        return $app;
    }

    protected function withExtensionInstall(string $installId): static
    {
        return $this->withHeader('X-Extension-Install-Id', $installId);
    }
}
