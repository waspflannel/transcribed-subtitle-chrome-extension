<?php

namespace App\Console\Commands;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('subtitles:runtime {--json : Output machine-readable JSON}')]
#[Description('Show subtitle queue, batch, and active job runtime state.')]
class ShowSubtitleRuntime extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $activeJobs = SubtitleJob::query()
            ->where('status', 'running')
            ->latest('updated_at')
            ->limit(25)
            ->get()
            ->map(fn (SubtitleJob $job): array => [
                'jobId' => $job->public_id,
                'runId' => $job->run_id,
                'videoId' => $job->youtube_video_id,
                'stage' => $job->stage,
                'progress' => $job->progress_percent,
                'updatedAt' => $job->updated_at->toJSON(),
            ])
            ->values();

        $summary = [
            'connection' => SubtitleGenerationPipeline::connection(),
            'driver' => config('queue.connections.'.SubtitleGenerationPipeline::connection().'.driver'),
            'queue' => SubtitleGenerationPipeline::queue(),
            'queueDepth' => $this->queueDepth(),
            'activeJobCount' => $activeJobs->count(),
        ];

        $batches = $this->recentBatches();
        $failures = SubtitleJobEvent::query()
            ->whereIn('event', ['job.failed', 'queue.failed', 'batch.failed'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (SubtitleJobEvent $event): array => [
                'time' => $event->created_at->toJSON(),
                'jobId' => $event->public_job_id,
                'event' => $event->event,
                'stage' => $event->stage,
                'errorCode' => $event->error_code,
                'exception' => $event->exception,
            ])
            ->values();

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'activeJobs' => $activeJobs,
                'batches' => $batches,
                'failures' => $failures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Subtitle runtime');
        $this->table(['connection', 'driver', 'queue', 'queue_depth', 'active_jobs'], [[
            $summary['connection'],
            $summary['driver'],
            $summary['queue'],
            $summary['queueDepth'],
            $summary['activeJobCount'],
        ]]);
        $this->table(['job_id', 'run_id', 'video', 'stage', 'progress', 'updated'], $activeJobs->all());
        $this->table(['batch_id', 'name', 'total', 'pending', 'failed', 'finished_at'], $batches->all());
        $this->table(['time', 'job_id', 'event', 'stage', 'error', 'exception'], $failures->all());

        return self::SUCCESS;
    }

    private function queueDepth(): int|string
    {
        $connection = SubtitleGenerationPipeline::connection();
        $driver = config('queue.connections.'.$connection.'.driver');

        try {
            if ($driver === 'database' && Schema::hasTable('jobs')) {
                return DB::table('jobs')
                    ->where('queue', SubtitleGenerationPipeline::queue())
                    ->count();
            }

            if ($driver === 'redis') {
                $redisConnection = (string) config('queue.connections.'.$connection.'.connection', 'default');

                return Redis::connection($redisConnection)->llen('queues:'.SubtitleGenerationPipeline::queue());
            }
        } catch (Throwable $exception) {
            return 'unavailable:'.$exception::class;
        }

        return 'unavailable';
    }

    private function recentBatches(): Collection
    {
        if (! Schema::hasTable('job_batches')) {
            return collect();
        }

        return DB::table('job_batches')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (object $batch): array => [
                'id' => $batch->id,
                'name' => $batch->name,
                'total' => $batch->total_jobs,
                'pending' => $batch->pending_jobs,
                'failed' => $batch->failed_jobs,
                'finishedAt' => $batch->finished_at,
            ])
            ->values();
    }
}
