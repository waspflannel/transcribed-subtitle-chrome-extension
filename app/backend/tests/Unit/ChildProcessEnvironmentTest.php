<?php

namespace Tests\Unit;

use App\Support\ChildProcessEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChildProcessEnvironmentTest extends TestCase
{
    public function test_child_process_cannot_read_parent_secrets(): void
    {
        $directory = storage_path('framework/testing/child-env-'.Str::uuid());
        putenv('TRANSCRIBE_CHILD_SECRET=hidden');
        $_ENV['TRANSCRIBE_CHILD_SECRET'] = 'hidden';

        try {
            $result = Process::env(ChildProcessEnvironment::isolated($directory))->run([PHP_BINARY, '-r',
                'echo json_encode([getenv("TRANSCRIBE_CHILD_SECRET"), getenv("OPENAI_API_KEY"), getenv("APP_KEY"), getenv("TMPDIR")]);',
            ]);

            $this->assertTrue($result->successful(), $result->errorOutput());
            $this->assertSame([false, false, false, $directory], json_decode($result->output(), true));
            $this->assertDirectoryExists($directory);
        } finally {
            putenv('TRANSCRIBE_CHILD_SECRET');
            unset($_ENV['TRANSCRIBE_CHILD_SECRET']);
            File::deleteDirectory($directory);
        }
    }
}
