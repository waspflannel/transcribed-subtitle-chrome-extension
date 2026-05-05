<?php

namespace App\Providers;

use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\TranslationAnalysisProvider;
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
        $this->app->bind(TranslationAnalysisProvider::class, LaravelAiTranslationAnalysisProvider::class);
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
                Limit::perMinute((int) config('subtitles.rate_limits.per_install_per_minute', 30))
                    ->by('install:'.$installId),
                Limit::perMinute((int) config('subtitles.rate_limits.per_ip_per_minute', 120))
                    ->by('ip:'.$request->ip()),
            ];
        });
    }
}
