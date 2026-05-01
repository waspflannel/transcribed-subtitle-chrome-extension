<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubtitleJobRequest;
use App\Http\Resources\SubtitleJobResource;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\JsonResponse;

class SubtitleJobController extends Controller
{
    public function store(CreateSubtitleJobRequest $request, SubtitleJobService $subtitleJobs): JsonResponse
    {
        $job = $subtitleJobs->generate(
            payload: $request->validated(),
            installId: $request->extensionInstallId(),
            requestIp: $request->ip(),
        );

        return response()->json(SubtitleJobResource::make($job)->resolve());
    }
}
