<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SubtitleRuntimeProfileTest extends TestCase
{
    public function test_runtime_check_allows_test_only_sqlite_profile(): void
    {
        config([
            'database.default' => 'sqlite',
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);

        $this->assertSame(0, Artisan::call('subtitles:runtime-check', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"ok": true', $output);
        $this->assertStringContainsString('"subtitleConcurrencyCacheStore"', $output);
        $this->assertStringContainsString('"subtitleConcurrencyCacheDriver"', $output);
    }

    public function test_runtime_check_flags_sqlite_when_strict(): void
    {
        config([
            'database.default' => 'sqlite',
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);

        $this->assertSame(1, Artisan::call('subtitles:runtime-check', ['--json' => true, '--strict' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('"ok": false', $output);
        $this->assertStringContainsString('expected pgsql', $output);
        $this->assertStringContainsString('expected redis', $output);
    }

    public function test_strict_runtime_check_requires_redis_concurrency_cache_when_subtitle_queue_uses_redis(): void
    {
        config([
            'database.default' => 'pgsql',
            'queue.default' => 'redis',
            'subtitles.queue.connection' => 'redis',
            'subtitles.providers.concurrency_cache_store' => 'array',
        ]);

        $this->assertSame(1, Artisan::call('subtitles:runtime-check', ['--json' => true, '--strict' => true]));

        $output = Artisan::output();
        $this->assertStringContainsString('"ok": false', $output);
        $this->assertStringContainsString('Subtitle concurrency cache driver is array; expected redis.', $output);
    }
}
