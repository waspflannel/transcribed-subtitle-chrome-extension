<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesExtensionUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelSubtitleLyricsRequest;
use App\Http\Requests\CorrectSubtitleLyricsRequest;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Requests\ListSubtitleJobsRequest;
use App\Http\Requests\PrefetchSubtitleAudioRequest;
use App\Http\Requests\QuickFixSubtitleTokenRequest;
use App\Http\Resources\SubtitleJobHistoryResource;
use App\Http\Resources\SubtitleJobResource;
use App\Http\Resources\SubtitleTrackLyricsCorrectionResource;
use App\Http\Resources\SubtitleTrackResource;
use App\Jobs\PrefetchSubtitleAudio;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubtitleJobController extends Controller
{
    use ResolvesExtensionUser;

    public function prefetchAudio(PrefetchSubtitleAudioRequest $request): JsonResponse
    {
        if (config('subtitles.youtube.metadata_prefetch', false)) {
            PrefetchSubtitleAudio::dispatch((int) $this->extensionUser($request)->id, $request->validated('youtubeVideoId'));
        }

        return response()->json(['ok' => true]);
    }

    public function index(ListSubtitleJobsRequest $request): JsonResponse
    {
        $now = now();
        $terminalCutoff = now()->subDays(30);
        $user = $this->extensionUser($request);
        $videoId = $request->validated('youtubeVideoId');
        $query = SubtitleJob::query()
            ->with('track:id,subtitle_job_id,public_id,generated_at,expires_at')
            ->whereBelongsTo($user);

        if ($videoId !== null) {
            $query->where('youtube_video_id', $videoId)
                ->where('status', 'completed')
                ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
                ->whereHas('track', fn ($query) => $query->where('expires_at', '>', $now));
        } else {
            $query
                ->where(function ($query) use ($now, $terminalCutoff): void {
                    $query
                        ->where(function ($query): void {
                            $query
                                ->whereIn('status', ['queued', 'running']);
                        })
                        ->orWhere(function ($query) use ($now): void {
                            $query
                                ->where('status', 'completed')
                                ->whereHas('track', fn ($query) => $query->where('expires_at', '>', $now));
                        })
                        ->orWhere(function ($query) use ($terminalCutoff): void {
                            $query
                                ->whereIn('status', ['failed', 'cancelled'])
                                ->where('updated_at', '>=', $terminalCutoff);
                        });
                })
                ->orderByRaw("case when status in ('queued', 'running') then 0 else 1 end")
                ->limit(25);
        }

        $jobs = $query->latest('updated_at')->latest('id')
            ->get()
            ->map(fn (SubtitleJob $job): array => SubtitleJobHistoryResource::make($job)->resolve())
            ->values();

        return response()->json(['jobs' => $jobs]);
    }

    public function store(CreateSubtitleJobRequest $request, SubtitleJobService $subtitleJobs): JsonResponse
    {
        $job = $subtitleJobs->generate(
            payload: $request->subtitlePayload(),
            user: $this->extensionUser($request),
            installId: $request->extensionInstallId(),
        );

        return response()->json(
            SubtitleJobResource::make($job)->resolve(),
            $job->hasReadyTrack() ? 200 : 202,
        );
    }

    public function show(Request $request, string $jobId): JsonResponse
    {
        $job = SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->whereBelongsTo($this->extensionUser($request))
            ->where(function ($query): void {
                $query
                    ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
                    ->orWhereIn('status', ['queued', 'running', 'failed', 'cancelled']);
            })
            ->first();

        if ($job === null || ($job->status === 'completed' && ! $job->hasReadyTrack())) {
            abort(404);
        }

        return response()->json(SubtitleJobResource::make($job)->resolve());
    }

    public function cancel(
        Request $request,
        string $jobId,
        SubtitleJobService $subtitleJobs,
    ): JsonResponse {
        $user = $this->extensionUser($request);
        $job = SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->whereBelongsTo($user)
            ->firstOrFail();

        $cancelled = $subtitleJobs->cancel($job, $user);

        return response()->json(SubtitleJobResource::make($cancelled)->resolve());
    }

    public function correctLyrics(
        CorrectSubtitleLyricsRequest $request,
        string $jobId,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = $this->ownedJob($request, $jobId);

        $correction = $corrections->submit(
            job: $job,
            user: $this->extensionUser($request),
            lyrics: $request->lyrics(),
            expectedTrackId: (string) $request->validated('expectedTrackId'),
            allowPartial: $request->allowPartial(),
        );

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve(), 202);
    }

    public function lyricsCorrectionStatus(Request $request, string $jobId): JsonResponse
    {
        $correction = SubtitleTrackLyricsCorrection::query()
            ->whereHas('track', fn ($query) => $query->where('expires_at', '>', now()))
            ->whereHas('track.job', function ($query) use ($request, $jobId): void {
                $query
                    ->where('public_id', $jobId)
                    ->whereBelongsTo($this->extensionUser($request));
            })
            ->firstOrFail();

        if ($correction->status === 'completed') {
            $correction->load('track.job');
        }

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve());
    }

    public function cancelLyrics(
        CancelSubtitleLyricsRequest $request,
        string $jobId,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = $this->ownedJob($request, $jobId);
        $correction = $corrections->cancel($job, $this->extensionUser($request), (string) $request->validated('attemptId'));

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve());
    }

    public function quickFixToken(
        QuickFixSubtitleTokenRequest $request,
        string $jobId,
        string $cueId,
        int $tokenIndex,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = $this->ownedJob($request, $jobId);
        $track = $corrections->quickFix(
            job: $job,
            user: $this->extensionUser($request),
            cueId: $cueId,
            tokenIndex: $tokenIndex,
            payload: $request->validated(),
        );

        return response()->json(SubtitleTrackResource::make($track)->resolve());
    }

    private function ownedJob(Request $request, string $jobId): SubtitleJob
    {
        return SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->whereBelongsTo($this->extensionUser($request))
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->firstOrFail();
    }
}
