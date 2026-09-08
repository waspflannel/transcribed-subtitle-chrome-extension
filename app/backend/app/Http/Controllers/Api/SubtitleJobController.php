<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesExtensionUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelSubtitleLyricsRequest;
use App\Http\Requests\CorrectSubtitleLyricsRequest;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Requests\QuickFixSubtitleTokenRequest;
use App\Http\Resources\SubtitleJobHistoryResource;
use App\Http\Resources\SubtitleJobResource;
use App\Http\Resources\SubtitleTrackLyricsCorrectionResource;
use App\Http\Resources\SubtitleTrackResource;
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
        $terminalCutoff = now()->subDays(30);
        $user = $this->extensionUser($request);
        $jobs = SubtitleJob::query()
            ->with('track')
            ->whereBelongsTo($user)
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
            ->with(['track.job'])
            ->whereHas('track', fn ($query) => $query->where('expires_at', '>', now()))
            ->whereHas('track.job', function ($query) use ($request, $jobId): void {
                $query
                    ->where('public_id', $jobId)
                    ->whereBelongsTo($this->extensionUser($request));
            })
            ->firstOrFail();

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
