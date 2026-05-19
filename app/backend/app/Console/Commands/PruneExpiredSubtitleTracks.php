<?php

namespace App\Console\Commands;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('subtitles:prune-expired')]
#[Description('Delete expired generated subtitle tracks and their now-empty jobs.')]
class PruneExpiredSubtitleTracks extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $expiredTracks = SubtitleTrack::query()
            ->where('expires_at', '<=', now())
            ->count();

        SubtitleTrack::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $expiredJobIds = SubtitleJob::query()
            ->where('expires_at', '<=', now())
            ->doesntHave('track')
            ->pluck('id');
        $expiredJobs = $expiredJobIds->count();
        $expiredEvents = $expiredJobIds->isEmpty()
            ? 0
            : SubtitleJobEvent::query()->whereIn('subtitle_job_id', $expiredJobIds)->count();

        SubtitleJob::query()
            ->where('expires_at', '<=', now())
            ->doesntHave('track')
            ->delete();

        Log::info('backend.expired_subtitles_pruned', [
            'expired_track_count' => $expiredTracks,
            'expired_job_count' => $expiredJobs,
        ]);

        $this->components->info("Pruned {$expiredTracks} expired subtitle tracks, {$expiredJobs} expired subtitle jobs, and {$expiredEvents} subtitle trace events.");

        return self::SUCCESS;
    }
}
