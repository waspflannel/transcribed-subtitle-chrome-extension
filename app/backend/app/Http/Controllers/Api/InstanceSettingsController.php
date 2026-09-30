<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateInstanceSettingsRequest;
use App\Services\InstanceSettings;
use Illuminate\Http\JsonResponse;

class InstanceSettingsController extends Controller
{
    public function show(InstanceSettings $settings): JsonResponse
    {
        return response()->json($settings->summary());
    }

    public function update(UpdateInstanceSettingsRequest $request, InstanceSettings $settings): JsonResponse
    {
        return response()->json($settings->update($request->validated()));
    }
}
