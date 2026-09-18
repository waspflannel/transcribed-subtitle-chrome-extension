<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_boots_only_disposable_services(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame(SUBTITLE_TEST_STORAGE, storage_path());
        $this->assertSame(SUBTITLE_TEST_STORAGE.DIRECTORY_SEPARATOR.'.env', $this->app->environmentFilePath());
        $this->assertSame('', file_get_contents($this->app->environmentFilePath()));
        $this->assertSame(SUBTITLE_TEST_STORAGE.'/config.php', $this->app->getCachedConfigPath());
    }

    public function test_artisan_ignores_inherited_database_and_cached_configuration(): void
    {
        $sentinel = storage_path('sentinel.sqlite');
        $database = new PDO('sqlite:'.$sentinel);
        $database->exec('CREATE TABLE sentinel (value TEXT)');
        $database->exec("INSERT INTO sentinel VALUES ('preserved')");
        // A relative path is recognized by Laravel on Windows as well as Unix.
        $cacheRelative = 'bootstrap/cache/host-test-'.bin2hex(random_bytes(8)).'.php';
        $cache = base_path($cacheRelative);
        file_put_contents($cache, '<?php throw new RuntimeException("Host configuration was loaded");');
        $hash = hash_file('sha256', $sentinel);

        $process = new Process([
            PHP_BINARY, 'artisan', '--no-ansi', 'test', '--compact',
            '--filter=test_boots_only_disposable_services',
        ], base_path(), [
            'APP_ENV' => 'staging',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $sentinel,
            'DB_URL' => 'sqlite:'.$sentinel,
            'APP_CONFIG_CACHE' => $cacheRelative,
            'CACHE_STORE' => 'redis',
            'SUBTITLE_CONCURRENCY_CACHE_STORE' => 'subtitle_concurrency',
            'SESSION_DRIVER' => 'database',
            'MAIL_MAILER' => 'smtp',
            'QUEUE_CONNECTION' => 'redis',
            'SUBTITLE_QUEUE_CONNECTION' => 'redis',
            'FILESYSTEM_DISK' => 's3',
            'LARAVEL_STORAGE_PATH' => dirname($sentinel).'/host-storage',
        ]);
        try {
            $process->setTimeout(60)->run();
        } finally {
            unlink($cache);
        }

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame($hash, hash_file('sha256', $sentinel));
        $this->assertSame('preserved', $database->query('SELECT value FROM sentinel')->fetchColumn());
    }
}
