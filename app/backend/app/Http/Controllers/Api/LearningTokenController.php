<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesExtensionUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnrichLearningTokenRequest;
use App\Services\TranslationAnalysis\LearningTokenEnrichmentService;
use Illuminate\Http\JsonResponse;

class LearningTokenController extends Controller
{
    use ResolvesExtensionUser;

    public function store(EnrichLearningTokenRequest $request, LearningTokenEnrichmentService $learningTokens): JsonResponse
    {
        return response()->json($learningTokens->enrich(
            payload: $request->validated(),
            user: $this->extensionUser($request),
        ));
    }
}
