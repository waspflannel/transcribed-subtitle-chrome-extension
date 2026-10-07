<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Codex\CodexService;
use Illuminate\Http\JsonResponse;

class CodexAccountController extends Controller
{
    public function show(CodexService $codex): JsonResponse
    {
        return response()->json($codex->summary())->header('Cache-Control', 'no-store');
    }

    public function login(CodexService $codex): JsonResponse
    {
        return response()->json($codex->startLogin(), 202)->header('Cache-Control', 'no-store');
    }

    public function destroy(CodexService $codex): JsonResponse
    {
        $codex->disconnect();

        return response()->json($codex->summary())->header('Cache-Control', 'no-store');
    }
}
