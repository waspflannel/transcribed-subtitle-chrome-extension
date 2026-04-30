<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        RateLimiter::for('subtitle-api', function (Request $request): array {
            $installId = (string) $request->header('X-Extension-Install-Id', 'missing');

            return [
                Limit::perMinute(30)->by('install:'.$installId),
                Limit::perMinute(120)->by('ip:'.$request->ip()),
            ];
        });
    }
}
