<?php

namespace App\Providers;

use App\Services\Audio\AudioSource;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\LaravelAiTranscriptionProvider;
use App\Services\Transcription\OpenAiVerboseTranscriptionProvider;
use App\Services\Transcription\TranscriptionProvider;
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
        $this->app->bind(AudioSource::class, YouTubeAudioSource::class);

        $this->app->bind(TranscriptionProvider::class, function () {
            return match (config('subtitles.transcription.provider')) {
                'laravel_ai_sdk' => $this->app->make(LaravelAiTranscriptionProvider::class),
                default => $this->app->make(OpenAiVerboseTranscriptionProvider::class),
            };
        });
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
