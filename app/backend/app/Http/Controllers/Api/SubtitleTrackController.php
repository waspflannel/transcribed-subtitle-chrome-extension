<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LookupSubtitleTrackRequest;
use App\Http\Resources\SubtitleTrackResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\JsonResponse;

class SubtitleTrackController extends Controller
{
    public function lookup(LookupSubtitleTrackRequest $request, SubtitleJobService $subtitleJobs): JsonResponse
    {
        $track = $subtitleJobs->findReadyTrack(
            youtubeVideoId: (string) $request->validated('youtubeVideoId'),
            sourceLanguage: (string) $request->validated('sourceLanguage'),
            targetLanguage: (string) $request->validated('targetLanguage'),
        );

        if (! $track) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'trackId' => $track->public_id,
            'status' => 'ready',
            'expiresAt' => $track->expires_at->toJSON(),
        ]);
    }

    public function show(SubtitleTrack $subtitleTrack): JsonResponse
    {
        if ($subtitleTrack->isExpired()) {
            return ApiErrorResponse::make('expired', 'Subtitle track has expired.', 410);
        }

        return response()->json(SubtitleTrackResource::make($subtitleTrack->load('job'))->resolve());
    }
}
