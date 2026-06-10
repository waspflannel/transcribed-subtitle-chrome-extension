<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesExtensionUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Resources\SubtitleJobHistoryResource;
use App\Http\Resources\SubtitleJobResource;
use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleJobService;
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
                            ->whereIn('status', ['running', 'failed'])
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
            requestIp: $request->ip(),
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
}
