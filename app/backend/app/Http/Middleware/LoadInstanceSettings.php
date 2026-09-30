<?php

namespace App\Http\Middleware;

use App\Services\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadInstanceSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        app(InstanceSettings::class)->apply();

        return $next($request);
    }
}
