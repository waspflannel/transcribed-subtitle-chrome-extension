<?php

namespace App\Console\Commands;

use App\Models\SubtitleJob;
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

        $expiredJobs = SubtitleJob::query()
            ->where('expires_at', '<=', now())
            ->doesntHave('track')
            ->count();

        SubtitleJob::query()
            ->where('expires_at', '<=', now())
            ->doesntHave('track')
            ->delete();

        Log::info('backend.expired_subtitles_pruned', [
            'expired_track_count' => $expiredTracks,
            'expired_job_count' => $expiredJobs,
        ]);

        $this->components->info("Pruned {$expiredTracks} expired subtitle tracks and {$expiredJobs} expired subtitle jobs.");

        return self::SUCCESS;
    }
}
