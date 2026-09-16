<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleAuthRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $limiter = match ($request->route()?->getName()) {
            'register.store' => 'registration',
            'password.email' => 'password-reset-mail',
            default => null,
        };

        return $limiter === null
            ? $next($request)
            : app(ThrottleRequests::class)->handle($request, $next, $limiter);
    }
}
