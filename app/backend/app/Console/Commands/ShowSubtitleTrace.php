<?php

namespace App\Console\Commands;

use App\Models\SubtitleJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subtitles:trace {jobId : Public subtitle job ID} {--json : Output machine-readable JSON}')]
#[Description('Show the sanitized runtime trace for a subtitle job.')]
class ShowSubtitleTrace extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jobId = (string) $this->argument('jobId');
        $job = SubtitleJob::query()
            ->with(['events' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->where('public_id', $jobId)
            ->first();

        if ($job === null) {
            $this->components->error("Subtitle job {$jobId} was not found.");

            return self::FAILURE;
        }

        $events = $job->events->map(fn ($event): array => [
            'time' => $event->created_at->toJSON(),
            'event' => $event->event,
            'stage' => $event->stage,
            'status' => $event->status,
            'runId' => $event->run_id,
            'queue' => $event->queue,
            'batchId' => $event->laravel_batch_id,
            'batchIndex' => $event->batch_index,
            'workerPid' => $event->worker_pid,
            'attempt' => $event->attempt,
            'durationMs' => $event->duration_ms,
            'waitMs' => $event->wait_ms,
            'errorCode' => $event->error_code,
            'exception' => $event->exception,
            'context' => $event->context ?? [],
        ])->values();

        if ($this->option('json')) {
            $this->line(json_encode([
                'jobId' => $job->public_id,
                'runId' => $job->run_id,
                'status' => $job->status,
                'stage' => $job->stage,
                'events' => $events,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Trace for {$job->public_id} ({$job->status}/{$job->stage})");
        $this->table(
            ['time', 'event', 'stage', 'batch', 'duration_ms', 'wait_ms', 'status', 'error', 'exception'],
            $events->map(fn (array $event): array => [
                $event['time'],
                $event['event'],
                $event['stage'] ?? '',
                $event['batchIndex'] ?? '',
                $event['durationMs'] ?? '',
                $event['waitMs'] ?? '',
                $event['status'] ?? '',
                $event['errorCode'] ?? '',
                $event['exception'] ?? '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
