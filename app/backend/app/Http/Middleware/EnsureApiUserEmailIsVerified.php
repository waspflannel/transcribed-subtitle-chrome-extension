<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiUserEmailIsVerified
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            return ApiErrorResponse::make(
                'email_not_verified',
                'Verify your email address before using the extension API.',
                403,
                request: $request,
            );
        }

        return $next($request);
    }
}
