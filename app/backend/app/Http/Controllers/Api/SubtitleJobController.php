<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesExtensionUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectSubtitleLyricsRequest;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Resources\SubtitleJobHistoryResource;
use App\Http\Resources\SubtitleJobResource;
use App\Http\Resources\SubtitleTrackLyricsCorrectionResource;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\Subtitles\LyricsCorrectionService;
use App\Services\Subtitles\SubtitleJobService;
use App\Services\Subtitles\SubtitlePartialTrackAssembler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubtitleJobController extends Controller
{
    use ResolvesExtensionUser;

    public function index(Request $request): JsonResponse
    {
        $now = now();
        $recentIncompleteCutoff = now()->subHours(4);
        $user = $this->extensionUser($request);
        $jobs = SubtitleJob::query()
            ->with('track')
            ->whereBelongsTo($user)
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function ($query) use ($now, $recentIncompleteCutoff): void {
                $query
                    ->where(function ($query) use ($now): void {
                        $query
                            ->where('expires_at', '>', $now)
                            ->whereHas('track', function ($query) use ($now): void {
                                $query
                                    ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
                                    ->where('expires_at', '>', $now);
                            });
                    })
                    ->orWhere(function ($query) use ($recentIncompleteCutoff): void {
                        $query
                            ->whereNull('expires_at')
                            ->whereDoesntHave('track')
                            ->whereIn('status', ['queued', 'running', 'failed'])
                            ->where('created_at', '>=', $recentIncompleteCutoff);
                    });
            })
            ->latest('updated_at')
            ->limit(25)
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
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->first();

        if ($job === null || ($job->status === 'completed' && ! $job->hasReadyTrack())) {
            abort(404);
        }

        return response()->json(SubtitleJobResource::make($job)->resolve());
    }

    /**
     * Cues already available for a still-running job. 404 until transcription
     * lands and once the job stops running; the extension polls the job and
     * treats 404 as not-yet-available.
     */
    public function partialTrack(
        Request $request,
        string $jobId,
        SubtitlePartialTrackAssembler $assembler,
    ): JsonResponse {
        $job = SubtitleJob::query()
            ->where('public_id', $jobId)
            ->whereBelongsTo($this->extensionUser($request))
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->first();

        if ($job === null || $job->status !== 'running') {
            abort(404);
        }

        $partialTrack = $assembler->assemble($job);

        if ($partialTrack === null) {
            abort(404);
        }

        return response()->json($partialTrack);
    }

    public function correctLyrics(
        CorrectSubtitleLyricsRequest $request,
        string $jobId,
        LyricsCorrectionService $corrections,
    ): JsonResponse {
        $job = SubtitleJob::query()
            ->with('track')
            ->where('public_id', $jobId)
            ->whereBelongsTo($this->extensionUser($request))
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->firstOrFail();

        $correction = $corrections->submit($job, $this->extensionUser($request), $request->lyrics());

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve(), 202);
    }

    public function lyricsCorrectionStatus(Request $request, string $jobId): JsonResponse
    {
        $correction = SubtitleTrackLyricsCorrection::query()
            ->with(['track.job'])
            ->whereHas('track', fn ($query) => $query->where('expires_at', '>', now()))
            ->whereHas('track.job', function ($query) use ($request, $jobId): void {
                $query
                    ->where('public_id', $jobId)
                    ->whereBelongsTo($this->extensionUser($request));
            })
            ->latest('updated_at')
            ->firstOrFail();

        return response()->json(SubtitleTrackLyricsCorrectionResource::make($correction)->resolve());
    }
}
