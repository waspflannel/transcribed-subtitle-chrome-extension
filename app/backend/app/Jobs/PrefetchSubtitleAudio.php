<?php

namespace App\Jobs;

use App\Services\Audio\YouTubeAudioSource;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrefetchSubtitleAudio implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public int $uniqueFor = 30;

    public readonly int $queuedAt;

    public function __construct(public readonly string $videoId)
    {
        $this->onConnection(SubtitleQueue::connection());
        $this->onQueue(SubtitleQueue::generationName());
        $this->queuedAt = time();
    }

    public function uniqueId(): string
    {
        return $this->videoId;
    }

    public function handle(YouTubeAudioSource $audio): void
    {
        if (time() - $this->queuedAt < 15) {
            $audio->prefetch($this->videoId);
        }
    }
}
