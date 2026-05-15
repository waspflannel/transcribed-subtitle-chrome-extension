<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('subtitle-api', function (Request $request): array {
            return [
                Limit::perMinute((int) config('subtitles.rate_limits.per_install_per_minute', 30))
                    ->by('install:'.$this->validatedInstallId($request, 'subtitle-api')),
                Limit::perMinute((int) config('subtitles.rate_limits.per_ip_per_minute', 120))
                    ->by('ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('subtitle-status-api', function (Request $request): array {
            return [
                Limit::perMinute((int) config('subtitles.rate_limits.status_per_install_per_minute', 120))
                    ->by('status-install:'.$this->validatedInstallId($request, 'subtitle-status-api')),
                Limit::perMinute((int) config('subtitles.rate_limits.status_per_ip_per_minute', 300))
                    ->by('status-ip:'.$request->ip()),
            ];
        });
    }

    private function validatedInstallId(Request $request, string $limiterName): string
    {
        $installId = $request->header('X-Extension-Install-Id');

        if (! is_string($installId) || $installId === '') {
            throw new LogicException($limiterName.' throttle requires a validated extension install ID.');
        }

        return $installId;
    }
}
