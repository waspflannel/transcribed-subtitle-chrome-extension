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
        $this->assertStringContainsString('"ok": true', Artisan::output());
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
}
