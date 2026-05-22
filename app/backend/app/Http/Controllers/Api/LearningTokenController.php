<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrichLearningTokenRequest;
use App\Models\User;
use App\Services\TranslationAnalysis\LearningTokenEnrichmentService;
use Illuminate\Http\JsonResponse;
use LogicException;

class LearningTokenController extends Controller
{
    public function store(EnrichLearningTokenRequest $request, LearningTokenEnrichmentService $learningTokens): JsonResponse
    {
        return response()->json($learningTokens->enrich(
            payload: $request->validated(),
            user: $this->extensionUser($request),
        ));
    }

    private function extensionUser(EnrichLearningTokenRequest $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Extension API request is missing an authenticated user.');
        }

        return $user;
    }
}
