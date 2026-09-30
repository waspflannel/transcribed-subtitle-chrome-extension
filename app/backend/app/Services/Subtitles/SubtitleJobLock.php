<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;

final class SubtitleJobLock
{
    /** Call inside a transaction before mutating a generation run. */
    public static function current(int $jobId, ?string $runId = null): ?SubtitleJob
    {
        return SubtitleJob::query()
            ->whereKey($jobId)
            ->when($runId !== null, fn ($query) => $query->where('run_id', $runId))
            ->lockForUpdate()
            ->first();
    }
}
