<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\SubtitleJobStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubtitleJobService
{
    public const PROCESSING_VERSION = 'mock-subtitles-v1';

    public function __construct(private readonly MockSubtitleTrackGenerator $tracks) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        return DB::transaction(function () use ($payload, $installId, $requestIp): SubtitleJob {
            $job = SubtitleJob::query()
                ->with('track')
                ->where('youtube_video_id', $payload['youtubeVideoId'])
                ->where('source_language', $payload['sourceLanguage'])
                ->where('target_language', $payload['targetLanguage'])
                ->where('processing_version', self::PROCESSING_VERSION)
                ->first();

            if ($job) {
                if ($this->hasReadyTrack($job)) {
                    return $job;
                }

                $this->resetJob($job, $payload, $installId, $requestIp);
            } else {
                $job = $this->createJob($payload, $installId, $requestIp);
            }

            $this->markProcessing($job);

            $track = $this->tracks->generate($job->refresh());
            $this->markCompleted($job, $track);

            return $job->refresh()->load('track');
        });
    }

    private function markProcessing(SubtitleJob $job): void
    {
        $job->update([
            'status' => SubtitleJobStatus::Processing,
            'progress_stage' => 'acquiring_audio',
            'progress_percent' => 15,
            'progress_message' => 'Preparing mock subtitle generation',
        ]);
    }

    private function markCompleted(SubtitleJob $job, SubtitleTrack $track): void
    {
        $job->update([
            'status' => SubtitleJobStatus::Completed,
            'progress_stage' => 'finalizing',
            'progress_percent' => 100,
            'progress_message' => 'Mock subtitle track ready',
            'error_code' => null,
            'error_message' => null,
            'error_details' => null,
            'expires_at' => $track->expires_at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        return SubtitleJob::create([
            'public_id' => (string) Str::uuid(),
            'youtube_video_id' => $payload['youtubeVideoId'],
            'youtube_url' => $payload['youtubeUrl'] ?? null,
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'source_language' => $payload['sourceLanguage'],
            'target_language' => $payload['targetLanguage'],
            'options' => $payload['options'],
            'processing_version' => self::PROCESSING_VERSION,
            'status' => SubtitleJobStatus::Queued,
            'progress_stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Queued',
            'install_id' => $installId,
            'request_ip' => $requestIp,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resetJob(SubtitleJob $job, array $payload, string $installId, ?string $requestIp): void
    {
        $job->track()->delete();
        $job->unsetRelation('track');

        $job->update([
            'youtube_url' => $payload['youtubeUrl'] ?? null,
            'video_duration_seconds' => $payload['videoDurationSeconds'] ?? null,
            'options' => $payload['options'],
            'status' => SubtitleJobStatus::Queued,
            'progress_stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Queued',
            'error_code' => null,
            'error_message' => null,
            'error_details' => null,
            'install_id' => $installId,
            'request_ip' => $requestIp,
            'expires_at' => null,
        ]);
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->status === SubtitleJobStatus::Completed
            && $job->expires_at?->isFuture()
            && $job->track !== null
            && ! $job->track->isExpired();
    }
}
