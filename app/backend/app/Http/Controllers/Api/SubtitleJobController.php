<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Resources\SubtitleJobResource;
use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

class SubtitleJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $now = now();
        $recentIncompleteCutoff = now()->subHours(4);
        $user = $this->extensionUser($request);
        $jobs = SubtitleJob::query()
            ->with('track')
            ->whereBelongsTo($user)
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
            user: $this->extensionUser($request),
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
            ->whereBelongsTo($this->extensionUser($request))
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
        $status = $this->requiredString($job->status, 'status');
        $enrichmentMode = $this->requiredEnrichmentMode($job->enrichment_mode);
        $includeRomanization = $this->requiredBoolean($job->include_romanization, 'include_romanization');
        $includeTranslation = $this->requiredBoolean($job->include_translation, 'include_translation');

        if (! in_array($status, ['running', 'completed', 'failed'], true)) {
            throw new LogicException('Subtitle job has an invalid status.');
        }

        if ($status === 'completed' && $track === null) {
            throw new LogicException('Completed subtitle job is missing a track.');
        }

        if ($track !== null && $status !== 'completed') {
            throw new LogicException('Subtitle job has a track before completion.');
        }

        $item = [
            'youtubeVideoId' => $job->youtube_video_id,
            'youtubeUrl' => $job->youtube_url,
            'status' => $status,
            'startedAt' => $job->created_at->toJSON(),
            'lastUpdatedAt' => $job->updated_at->toJSON(),
            'sourceLanguage' => $job->source_language,
            'targetLanguage' => $job->target_language,
            'enrichmentMode' => $enrichmentMode,
            'includeRomanization' => $includeRomanization,
            'includeTranslation' => $includeTranslation,
            'jobId' => $job->public_id,
            'stage' => $this->requiredString($job->stage, 'stage'),
            'progressPercent' => $this->requiredProgressPercent($job->progress_percent),
        ];

        if (is_int($job->video_duration_seconds)) {
            $item['videoDurationSeconds'] = $job->video_duration_seconds;
        }

        if (is_string($job->detected_source_language) && $job->detected_source_language !== '') {
            $item['detectedSourceLanguage'] = $job->detected_source_language;
        }

        if ($track !== null) {
            $item['completedAt'] = $track->generated_at->toJSON();
            $item['trackId'] = $track->public_id;
            $item['expiresAt'] = $track->expires_at->toJSON();
        }

        if ($status === 'failed') {
            $item['completedAt'] = $job->updated_at->toJSON();
            $item['errorCode'] = $this->requiredString($job->error_code, 'error_code');
            $item['message'] = $this->requiredString($job->error_message, 'error_message');
        }

        return $item;
    }

    private function hasReadyTrack(SubtitleJob $job): bool
    {
        return $job->track !== null
            && ! $job->track->isExpired();
    }

    private function requiredEnrichmentMode(mixed $value): string
    {
        if ($value !== 'on_demand' && $value !== 'full') {
            throw new LogicException('Subtitle job has an invalid enrichment_mode.');
        }

        return $value;
    }

    private function requiredBoolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new LogicException("Subtitle job is missing {$field}.");
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new LogicException("Subtitle job is missing {$field}.");
        }

        return $value;
    }

    private function requiredProgressPercent(mixed $value): int
    {
        if (! is_int($value)) {
            throw new LogicException('Subtitle job is missing progress_percent.');
        }

        return $value;
    }

    private function extensionUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Extension API request is missing an authenticated user.');
        }

        return $user;
    }
}
