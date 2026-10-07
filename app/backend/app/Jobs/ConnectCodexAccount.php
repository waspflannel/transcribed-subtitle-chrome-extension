<?php

namespace App\Jobs;

use App\Services\Codex\CodexService;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConnectCodexAccount implements ShouldQueue
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

    public function handle(CodexService $codex): void
    {
        $codex->connect($this->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        app(CodexService::class)->failLogin($this->attempt);
    }
}
