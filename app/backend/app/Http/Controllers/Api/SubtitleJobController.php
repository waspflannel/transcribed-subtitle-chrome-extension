<?php

namespace App\Http\Controllers\Api;

use App\Ai\SubtitleModel;
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
    public function prefetchAudio(PrefetchSubtitleAudioRequest $request): JsonResponse
    {
        if (config('subtitles.youtube.metadata_prefetch', false)) {
            PrefetchSubtitleAudio::dispatch($request->validated('youtubeVideoId'));
        }

        return response()->json(['ok' => true]);
    }

    public function index(ListSubtitleJobsRequest $request): JsonResponse
    {
        $now = now();
        $terminalCutoff = now()->subDays(30);
        $videoId = $request->validated('youtubeVideoId');
        $query = SubtitleJob::query()
            ->with('track:id,subtitle_job_id,public_id,generated_at,expires_at');

        if ($videoId !== null) {
            $query->where('youtube_video_id', $videoId)
                ->where('status', 'completed')
                ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
                ->whereHas('track', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)));
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
                                ->whereHas('track', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)));
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

    public function destroyGeneration(Request $request, string $jobId, SubtitleJobService $subtitleJobs): JsonResponse
    {
        $job = SubtitleJob::query()
            ->where('public_id', $jobId)
            ->where('status', 'completed')
            ->firstOrFail();

        abort_unless($subtitleJobs->delete($job, completedOnly: true), 404);

        return response()->json(['ok' => true]);
    }

    public function cancel(
        Request $request,
        string $jobId,
        SubtitleJobService $subtitleJobs,
    ): JsonResponse {
        $job = SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->firstOrFail();

        $cancelled = $subtitleJobs->cancel($job);

        return response()->json(SubtitleJobResource::make($cancelled)->resolve());
    }

    public function correctLyrics(
        CorrectSubtitleLyricsRequest $request,
        string $jobId,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = $this->findJob($jobId);

        $correction = $corrections->submit(
            job: $job,
            lyrics: $request->lyrics(),
            expectedTrackId: (string) $request->validated('expectedTrackId'),
            selection: $request->validated('aiProvider') !== null
                ? SubtitleModel::configured($request->validated('aiProvider'), $request->validated('aiModel'), $request->boolean('aiFastMode'))
                : null,
        );

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve(), 202);
    }

    public function lyricsCorrectionStatus(Request $request, string $jobId): JsonResponse
    {
        $correction = SubtitleTrackLyricsCorrection::query()
            ->with('track.job')
            ->whereHas('track', fn ($query) => $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->whereHas('track.job', fn ($query) => $query->where('public_id', $jobId))
            ->firstOrFail();

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve());
    }

    public function cancelLyrics(
        CancelSubtitleLyricsRequest $request,
        string $jobId,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = $this->findJob($jobId);
        $correction = $corrections->cancel($job, (string) $request->validated('attemptId'));

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve());
    }

    public function quickFixToken(
        QuickFixSubtitleTokenRequest $request,
        string $jobId,
        string $cueId,
        int $tokenIndex,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = $this->findJob($jobId);
        $track = $corrections->quickFix(
            job: $job,
            cueId: $cueId,
            tokenIndex: $tokenIndex,
            payload: $request->validated(),
        );

        return response()->json(SubtitleTrackResource::make($track)->resolve());
    }

    private function findJob(string $jobId): SubtitleJob
    {
        return SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->firstOrFail();
    }
}
