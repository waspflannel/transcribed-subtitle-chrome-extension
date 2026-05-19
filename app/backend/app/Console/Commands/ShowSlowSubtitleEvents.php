<?php

namespace App\Console\Commands;

use App\Models\SubtitleJobEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subtitles:slow {--limit=25 : Maximum number of events} {--json : Output machine-readable JSON}')]
#[Description('Show recent slow subtitle queue waits and stage timings.')]
class ShowSlowSubtitleEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $events = SubtitleJobEvent::query()
            ->where(function ($query): void {
                $query
                    ->where('event', 'stage.slow')
                    ->orWhere('duration_ms', '>', 0)
                    ->orWhere('wait_ms', '>', 0);
            })
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (SubtitleJobEvent $event): array => [
                'time' => $event->created_at->toJSON(),
                'jobId' => $event->public_job_id,
                'runId' => $event->run_id,
                'event' => $event->event,
                'stage' => $event->stage,
                'batchIndex' => $event->batch_index,
                'durationMs' => $event->duration_ms,
                'waitMs' => $event->wait_ms,
                'thresholdMs' => $event->context['threshold_ms'] ?? null,
                'slowType' => $event->context['slow_type'] ?? null,
            ])
            ->values();

        if ($this->option('json')) {
            $this->line(json_encode(['events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['time', 'job_id', 'event', 'stage', 'batch', 'duration_ms', 'wait_ms', 'threshold_ms', 'type'],
            $events->map(fn (array $event): array => [
                $event['time'],
                $event['jobId'],
                $event['event'],
                $event['stage'],
                $event['batchIndex'] ?? '',
                $event['durationMs'] ?? '',
                $event['waitMs'] ?? '',
                $event['thresholdMs'] ?? '',
                $event['slowType'] ?? '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
