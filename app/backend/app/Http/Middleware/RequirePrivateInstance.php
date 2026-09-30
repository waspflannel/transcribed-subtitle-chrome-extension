<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class RequirePrivateInstance
{
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = ['localhost', '127.0.0.1', '[::1]', parse_url((string) config('app.url'), PHP_URL_HOST)];
        $origin = $request->header('Origin');
        $originAllowed = $origin === null
            || $origin === $request->getSchemeAndHttpHost()
            || preg_match('/\Achrome-extension:\/\/[a-p]{32}\z/D', $origin) === 1;

        if (! IpUtils::checkIp($request->ip() ?? '', config('instance.allowed_networks', []))
            || ! in_array($request->getHost(), $hosts, true)
            || ! $originAllowed) {
            if ($request->is('v1/*')) {
                return ApiErrorResponse::make('instance_access_denied', 'This personal instance is only available from its configured trusted network.', 403, request: $request);
            }

            abort(403, 'This personal instance is only available from its configured trusted network.');
        }

        return $next($request);
    }
}
