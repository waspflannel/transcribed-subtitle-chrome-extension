<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Models\User;

final class SubtitleJobLock
{
    /** Call inside a transaction; every billing/job mutation locks account before job. */
    public static function current(int $jobId, ?string $runId = null, ?int $userId = null): ?SubtitleJob
    {
        $userId ??= SubtitleJob::query()->whereKey($jobId)->value('user_id');

        if ($userId !== null) {
            User::query()->whereKey($userId)->lockForUpdate()->first();
        }

        return SubtitleJob::query()
            ->whereKey($jobId)
            ->where('user_id', $userId)
            ->when($runId !== null, fn ($query) => $query->where('run_id', $runId))
            ->lockForUpdate()
            ->first();
    }
}
