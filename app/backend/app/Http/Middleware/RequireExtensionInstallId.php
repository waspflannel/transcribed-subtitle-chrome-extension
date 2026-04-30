<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireExtensionInstallId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $installId = $request->header('X-Extension-Install-Id');

        if (! is_string($installId) || preg_match('/^[A-Za-z0-9_-]{16,128}$/', $installId) !== 1) {
            return ApiErrorResponse::make(
                'validation_failed',
                'Missing or invalid extension install ID.',
                422,
                ['headers' => ['X-Extension-Install-Id' => ['The extension install ID is required.']]],
            );
        }

        return $next($request);
    }
}
