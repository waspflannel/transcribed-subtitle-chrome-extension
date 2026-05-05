<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(
        string $code,
        string $message,
        int $status,
        array $details = [],
        ?Request $request = null,
    ): JsonResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        $payload = ['error' => $error];

        if ($request !== null) {
            $payload['requestId'] = self::requestId($request);
        }

        return response()->json($payload, $status);
    }

    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get('api_request_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $header = $request->header('X-Request-Id');
        $requestId = is_string($header) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $header) === 1
            ? $header
            : 'req_'.str_replace('-', '', (string) Str::uuid());

        $request->attributes->set('api_request_id', $requestId);

        return $requestId;
    }
}
