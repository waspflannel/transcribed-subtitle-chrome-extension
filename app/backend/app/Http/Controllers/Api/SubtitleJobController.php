<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Resources\SubtitleJobResource;
use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubtitleJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = now();
        $recentIncompleteCutoff = now()->subHours(4);
        $jobs = SubtitleJob::query()
            ->with('track')
            ->where('install_id', (string) $request->header('X-Extension-Install-Id'))
            ->whereIn('processing_version', SubtitleJobService::CURRENT_PROCESSING_VERSIONS)
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function ($query) use ($now, $recentIncompleteCutoff): void {
                $query
                    ->where(function ($query) use ($now): void {
                        $query
                            ->where('expires_at', '>', $now)
                            ->whereHas('track', function ($query) use ($now): void {
                                $query
                                    ->whereIn('processing_version', SubtitleJobService::CURRENT_PROCESSING_VERSIONS)
                                    ->where('expires_at', '>', $now);
                            });
                    })
                    ->orWhere(function ($query) use ($recentIncompleteCutoff): void {
                        $query
                            ->whereNull('expires_at')
                            ->whereDoesntHave('track')
                            ->whereIn('status', ['running', 'failed'])
                            ->where('created_at', '>=', $recentIncompleteCutoff);
                    });
            })
            ->latest('updated_at')
            ->limit(25)
            ->get()
            ->map(fn (SubtitleJob $job): array => $this->jobHistoryItem($job))
            ->values();

        return response()->json(['jobs' => $jobs]);
    }

    public function store(CreateSubtitleJobRequest $request, SubtitleJobService $subtitleJobs): JsonResponse
    {
        $job = $subtitleJobs->generate(
            payload: $request->subtitlePayload(),
            installId: $request->extensionInstallId(),
            requestIp: $request->ip(),
        );

        return response()->json(
            SubtitleJobResource::make($job)->resolve(),
            $this->hasReadyTrack($job) ? 200 : 202,
        );
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->where('install_id', (string) $request->header('X-Extension-Install-Id'))
            ->whereIn('processing_version', SubtitleJobService::CURRENT_PROCESSING_VERSIONS)
            ->first();

        if ($job === null || ($job->status === 'completed' && ! $this->hasReadyTrack($job))) {
            abort(404);
        }

        return response()->json(SubtitleJobResource::make($job)->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    private function jobHistoryItem(SubtitleJob $job): array
    {
        $track = $job->track;
        $status = $track !== null ? 'completed' : ($job->status === 'failed' ? 'failed' : 'running');
        $item = [
            'youtubeVideoId' => $job->youtube_video_id,
            'youtubeUrl' => $job->youtube_url,
            'status' => $status,
            'startedAt' => $job->created_at->toJSON(),
            'lastUpdatedAt' => $job->updated_at->toJSON(),
            'sourceLanguage' => $job->source_language,
            'targetLanguage' => $job->target_language,
            'jobId' => $job->public_id,
        ];

        if (is_string($job->detected_source_language) && $job->detected_source_language !== '') {
            $item['detectedSourceLanguage'] = $job->detected_source_language;
        }

        if (is_string($job->stage) && $job->stage !== '') {
            $item['stage'] = $job->stage;
        }

        if ($job->progress_percent !== null) {
            $item['progressPercent'] = $job->progress_percent;
        }

        if ($track !== null) {
            $item['completedAt'] = $track->generated_at->toJSON();
            $item['trackId'] = $track->public_id;
            $item['expiresAt'] = $track->expires_at->toJSON();
        }

        if ($status === 'failed') {
            $item['completedAt'] = $job->updated_at->toJSON();
            $item['message'] = $job->error_message ?: 'Generation did not complete.';
        }

        return $item;
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }
}
