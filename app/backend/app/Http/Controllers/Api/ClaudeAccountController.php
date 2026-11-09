<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ClaudeCode\ClaudeAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaudeAccountController extends Controller
{
    public function show(ClaudeAccountService $claude): JsonResponse
    {
        return response()->json($claude->summary())->header('Cache-Control', 'no-store');
    }

    public function login(ClaudeAccountService $claude): JsonResponse
    {
        return response()->json($claude->startLogin(), 202)->header('Cache-Control', 'no-store');
    }

    public function code(Request $request, ClaudeAccountService $claude): JsonResponse
    {
        // The Claude page shows "<code>#<state>"; terminals and copy buttons can add whitespace.
        $request->merge(['code' => is_string($request->input('code')) ? preg_replace('/\s+/u', '', $request->input('code')) : $request->input('code')]);
        $validated = $request->validate(['code' => ['required', 'string', 'max:512', 'regex:/\A[A-Za-z0-9._~#-]+\z/D']]);

        return response()->json($claude->submitCode($validated['code']), 202)->header('Cache-Control', 'no-store');
    }

    public function destroy(ClaudeAccountService $claude): JsonResponse
    {
        return response()->json($claude->disconnect())->header('Cache-Control', 'no-store');
    }
}
