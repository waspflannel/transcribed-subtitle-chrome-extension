<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
