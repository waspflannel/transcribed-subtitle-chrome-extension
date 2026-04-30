<?php

namespace App\Services\Subtitles;

use App\Jobs\ProcessSubtitleJob;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\SubtitleJobStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubtitleJobService
{
    public const PROCESSING_VERSION = 'mock-subtitles-v1';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOrReuse(array $payload, string $installId, ?string $requestIp): SubtitleJob
    {
        return DB::transaction(function () use ($payload, $installId, $requestIp): SubtitleJob {
            $job = SubtitleJob::query()
                ->where('youtube_video_id', $payload['youtubeVideoId'])
                ->where('source_language', $payload['sourceLanguage'])
                ->where('target_language', $payload['targetLanguage'])
                ->where('processing_version', self::PROCESSING_VERSION)
                ->first();

            if ($job) {
                return $this->refreshExpiredJob($job)->load('track');
            }

            $job = SubtitleJob::create([
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

            ProcessSubtitleJob::dispatch($job)->afterCommit();

            return $job->load('track');
        });
    }

    public function findReadyTrack(string $youtubeVideoId, string $sourceLanguage, string $targetLanguage): ?SubtitleTrack
    {
        return SubtitleTrack::query()
            ->where('youtube_video_id', $youtubeVideoId)
            ->where('source_language', $sourceLanguage)
            ->where('target_language', $targetLanguage)
            ->where('processing_version', self::PROCESSING_VERSION)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function markProcessing(SubtitleJob $job): void
    {
        $job->update([
            'status' => SubtitleJobStatus::Processing,
            'progress_stage' => 'acquiring_audio',
            'progress_percent' => 15,
            'progress_message' => 'Preparing mock subtitle generation',
        ]);
    }

    public function markCompleted(SubtitleJob $job, SubtitleTrack $track): void
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

    private function refreshExpiredJob(SubtitleJob $job): SubtitleJob
    {
        if ($job->expires_at?->isPast() && $job->status !== SubtitleJobStatus::Expired) {
            $job->update(['status' => SubtitleJobStatus::Expired]);
        }

        return $job;
    }
}
