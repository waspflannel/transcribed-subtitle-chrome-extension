<?php

namespace App\Console\Commands;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

#[Signature('subtitles:metrics {--days=7 : Completed jobs to include by created-at window} {--json : Output machine-readable JSON}')]
#[Description('Show subtitle generation timing, queue wait, and provider-cost metrics.')]
class ShowSubtitleGenerationMetrics extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $jobs = SubtitleJob::query()
            ->with(['events' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        $rows = $jobs
            ->map(fn (SubtitleJob $job): ?array => $this->metricsRow($job))
            ->filter()
            ->values();

        $groups = $rows
            ->groupBy(fn (array $row): string => json_encode(Arr::only($row, ['durationBucket', 'aiProvider', 'aiModel', 'aiFastMode', 'processingVersion', 'transcriptCacheHit'])))
            ->map(fn (Collection $group): array => $this->groupMetrics($group))
            ->sortBy('durationBucket')
            ->values();

        $summary = [
            'days' => $days,
            'completedJobCount' => $rows->count(),
            'totalCostMicrousd' => $rows->sum('costMicrousd'),
            'totalGeneratedMinutes' => round($rows->sum('generatedMinutes'), 2),
            'costPerGeneratedMinuteMicrousd' => $this->costPerGeneratedMinute($rows),
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'groups' => $groups,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Subtitle generation metrics');
        $this->table(['metric', 'value'], collect($summary)
            ->map(fn (mixed $value, string $key): array => [$key, $value])
            ->values()
            ->all());
        $this->table(
            ['bucket', 'model', 'fast', 'cached', 'jobs', 'source_p50_ms', 'ready_p50_ms', 'total_p50_ms', 'total_p95_ms', 'p95_wait_ms', 'cost_per_min_microusd'],
            $groups
                ->map(fn (array $group): array => [
                    $group['durationBucket'],
                    $group['aiProvider'].'/'.$group['aiModel'],
                    $group['aiFastMode'] ? 'yes' : 'no',
                    $group['transcriptCacheHit'] ? 'yes' : 'no',
                    $group['completedJobCount'],
                    $group['p50FirstCueMs'] ?? 'n/a',
                    $group['p50FirstAnnotatedCueMs'] ?? 'n/a',
                    $group['p50DurationMs'],
                    $group['p95DurationMs'],
                    $group['p95QueueWaitMs'],
                    $group['costPerGeneratedMinuteMicrousd'],
                ])
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function metricsRow(SubtitleJob $job): ?array
    {
        $events = $job->events->where('run_id', $job->run_id);
        $completed = $events->firstWhere('event', 'job.completed');

        if (! $completed instanceof SubtitleJobEvent || ! is_int($completed->duration_ms)) {
            return null;
        }

        $videoDurationSeconds = max(1, (int) ($job->video_duration_seconds ?? 0));
        $bucket = $videoDurationSeconds <= 300 ? 'short' : ($videoDurationSeconds <= 1800 ? 'medium' : 'long');
        $queueWaits = $events
            ->where('event', 'queue.wait_observed')
            ->pluck('wait_ms')
            ->filter(fn (mixed $value): bool => is_int($value))
            ->values();

        return [
            'durationBucket' => $bucket,
            'aiProvider' => $job->ai_provider,
            'aiModel' => $job->ai_model,
            'aiFastMode' => (bool) $job->ai_fast_mode,
            'processingVersion' => $job->processing_version,
            'transcriptCacheHit' => $events->contains('event', 'transcript.cache_hit'),
            'firstCueMs' => $events->firstWhere('event', 'delivery.first_cue_available')?->duration_ms,
            'firstAnnotatedCueMs' => $events->firstWhere('event', 'delivery.first_annotated_cue_available')?->duration_ms,
            'durationMs' => $completed->duration_ms,
            'queueWaitMs' => $queueWaits->max() ?? 0,
            'costMicrousd' => (int) $job->estimated_provider_cost_microusd,
            'generatedMinutes' => $videoDurationSeconds / 60,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function groupMetrics(Collection $group): array
    {
        $first = $group->first();

        return [
            ...Arr::only($first, ['durationBucket', 'aiProvider', 'aiModel', 'aiFastMode', 'processingVersion', 'transcriptCacheHit']),
            'firstCueSampleCount' => $group->whereNotNull('firstCueMs')->count(),
            'firstAnnotatedCueSampleCount' => $group->whereNotNull('firstAnnotatedCueMs')->count(),
            'p50FirstCueMs' => $this->percentile($group->pluck('firstCueMs'), 50),
            'p95FirstCueMs' => $this->percentile($group->pluck('firstCueMs'), 95),
            'p50FirstAnnotatedCueMs' => $this->percentile($group->pluck('firstAnnotatedCueMs'), 50),
            'p95FirstAnnotatedCueMs' => $this->percentile($group->pluck('firstAnnotatedCueMs'), 95),
            'completedJobCount' => $group->count(),
            'p50DurationMs' => $this->percentile($group->pluck('durationMs'), 50),
            'p95DurationMs' => $this->percentile($group->pluck('durationMs'), 95),
            'p95QueueWaitMs' => $this->percentile($group->pluck('queueWaitMs'), 95),
            'costPerGeneratedMinuteMicrousd' => $this->costPerGeneratedMinute($group),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function percentile(Collection $values, int $percentile): ?int
    {
        $sorted = $values
            ->filter(fn (mixed $value): bool => is_int($value) || is_float($value))
            ->sort()
            ->values();

        if ($sorted->isEmpty()) {
            return null;
        }

        $index = (int) ceil(($percentile / 100) * $sorted->count()) - 1;

        return (int) $sorted->get(max(0, min($index, $sorted->count() - 1)));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function costPerGeneratedMinute(Collection $rows): int
    {
        $minutes = (float) $rows->sum('generatedMinutes');

        if ($minutes <= 0.0) {
            return 0;
        }

        return (int) round((int) $rows->sum('costMicrousd') / $minutes);
    }
}
