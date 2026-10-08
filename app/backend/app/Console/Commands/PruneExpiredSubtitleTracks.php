<?php

namespace App\Console\Commands;

use App\Models\CachedVideoTranscript;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Models\SubtitleTrack;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('subtitles:prune-expired')]
#[Description('Delete expired generated subtitle tracks, their now-empty jobs, expired cached video transcripts, and old trace events.')]
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
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('expires_at', '<=', now())
            ->doesntHave('track')
            ->pluck('id');
        $expiredJobs = $expiredJobIds->count();
        $expiredEvents = $expiredJobIds->isEmpty()
            ? 0
            : SubtitleJobEvent::query()->whereIn('subtitle_job_id', $expiredJobIds)->count();

        SubtitleJob::query()
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('expires_at', '<=', now())
            ->doesntHave('track')
            ->delete();

        $expiredTranscripts = CachedVideoTranscript::query()
            ->where('expires_at', '<=', now())
            ->count();

        CachedVideoTranscript::query()
            ->where('expires_at', '<=', now())
            ->delete();

        // Kept tracks keep their job, so their trace events need their own limit.
        $eventRetentionDays = (int) config('subtitles.tracing.event_retention_days', 30);
        $oldEvents = $eventRetentionDays > 0
            ? SubtitleJobEvent::query()->where('created_at', '<', now()->subDays($eventRetentionDays))->delete()
            : 0;

        Log::info('backend.expired_subtitles_pruned', [
            'expired_track_count' => $expiredTracks,
            'expired_job_count' => $expiredJobs,
            'expired_cached_transcript_count' => $expiredTranscripts,
            'old_trace_event_count' => $oldEvents,
        ]);

        $this->components->info("Pruned {$expiredTracks} expired subtitle tracks, {$expiredJobs} expired subtitle jobs, {$expiredEvents} subtitle trace events, {$expiredTranscripts} expired cached video transcripts, and {$oldEvents} old trace events.");

        return self::SUCCESS;
    }
}
