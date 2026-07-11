<?php

namespace App\Jobs;

use App\Services\Audio\TemporaryAudioFile;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Second generation stage: normalizes the downloaded audio for Scribe
 * (ffmpeg, optionally voice isolation), splits it into transcription chunks,
 * and fans the chunks out as their own queue jobs.
 */
class OptimizeSubtitleAudio implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $maxExceptions = 1;

    /**
     * Voice isolation adds a provider round-trip and two extra ffmpeg passes
     * (600s process timeout each), so this stage keeps the old whole-span
     * ceiling instead of a tighter one.
     */
    public int $timeout = 1200;

    public readonly int $queuedAtMs;

    public function __construct(
        public readonly int $subtitleJobId,
        public readonly string $runId,
        public readonly TemporaryAudioFile $audio,
        ?int $queuedAtMs = null,
    ) {
        $this->onConnection(SubtitleQueue::connection());
        $this->queuedAtMs = $queuedAtMs ?? (int) floor(microtime(true) * 1000);
    }

    public function handle(SubtitleGenerationPipeline $pipeline): void
    {
        $pipeline->optimizeAudioAndDispatchTranscription(
            $this->subtitleJobId,
            $this->runId,
            $this->audio,
            $this->queuedAtMs,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(SubtitleJobFailureHandler::class)->failJob(
            $this->subtitleJobId,
            'optimizing-audio',
            $exception ?? new RuntimeException('Subtitle audio optimization job failed.'),
            $this->runId,
        );
    }
}
