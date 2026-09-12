<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('subtitle_jobs')
            ->whereIn('status', ['failed', 'cancelled'])
            ->whereNull('expires_at')
            ->select(['id', 'status', 'updated_at', 'created_at'])
            ->chunkById(500, function (Collection $jobs): void {
                foreach ($jobs as $job) {
                    DB::table('subtitle_jobs')
                        ->where('id', $job->id)
                        ->where('status', $job->status)
                        ->where('updated_at', $job->updated_at)
                        ->whereNull('expires_at')
                        ->update(['expires_at' => CarbonImmutable::parse($job->updated_at ?? $job->created_at)->addDays(30)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Expired jobs may already have been pruned; retain the assigned diagnostic deadlines.
    }
};
