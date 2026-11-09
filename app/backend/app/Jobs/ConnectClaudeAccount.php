<?php

namespace App\Jobs;

use App\Services\ClaudeCode\ClaudeAccountService;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConnectClaudeAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 660;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $attempt)
    {
        $this->onConnection(SubtitleQueue::connection());
        $this->onQueue(SubtitleQueue::generationName());
    }

    public function handle(ClaudeAccountService $claude): void
    {
        $claude->connect($this->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        app(ClaudeAccountService::class)->failLogin($this->attempt);
    }
}
