<?php

namespace App\Providers;

use App\Services\Subtitles\SubtitleRuntimeTracer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\GroqProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Ai::extend('cerebras', fn ($app, array $config): GroqProvider => new GroqProvider($config, $app->make(Dispatcher::class)));

        JsonResource::withoutWrapping();
        $this->registerSubtitleQueueTracing();
        $this->registerOpenAiResponseTracing();

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

        RateLimiter::for('subtitle-ai-batch', function (): Limit {
            return Limit::perMinute(max(1, (int) config('subtitles.enrichment.global_rate_limit_per_minute', 300)));
        });

        RateLimiter::for('extension-auth', function (Request $request): array {
            $email = $request->input('email');
            $emailKey = is_string($email) ? Str::lower($email) : 'invalid-email';

            return [
                Limit::perMinute(5)->by('extension-auth-email:'.$emailKey),
                Limit::perMinute(20)->by('extension-auth-ip:'.$request->ip()),
            ];
        });
    }

    private function registerOpenAiResponseTracing(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $url = rtrim(config('ai.providers.openai.url') ?? 'https://api.openai.com/v1', '/').'/responses';

            if ($event->request->url() !== $url || ($event->request['stream'] ?? false) === true) {
                return;
            }

            $stats = $event->response->handlerStats();
            $milliseconds = static fn (mixed $seconds): ?int => is_numeric($seconds) ? (int) round((float) $seconds * 1000) : null;

            // Select diagnostics explicitly: never log request/response bodies,
            // authorization headers, prompts, or generated text.
            Log::info('backend.openai_response_received', [
                'worker_pid' => getmypid() ?: null,
                'request_id' => $event->response->header('x-request-id') ?: null,
                'model' => $event->request['model'] ?? null,
                'requested_service_tier' => $event->request['service_tier'] ?? null,
                'served_service_tier' => $event->response->json('service_tier'),
                'reasoning_effort' => data_get($event->request->data(), 'reasoning.effort'),
                'http_status' => $event->response->status(),
                'response_status' => $event->response->json('status'),
                'incomplete_reason' => $event->response->json('incomplete_details.reason'),
                'error_code' => $event->response->json('error.code'),
                'duration_ms' => $milliseconds($stats['total_time'] ?? null),
                'connect_ms' => $milliseconds($stats['connect_time'] ?? null),
                'time_to_first_byte_ms' => $milliseconds($stats['starttransfer_time'] ?? null),
                'provider_processing_ms' => is_numeric($event->response->header('openai-processing-ms'))
                    ? (int) $event->response->header('openai-processing-ms') : null,
                'input_tokens' => $event->response->json('usage.input_tokens'),
                'cached_input_tokens' => $event->response->json('usage.input_tokens_details.cached_tokens'),
                'output_tokens' => $event->response->json('usage.output_tokens'),
                'reasoning_tokens' => $event->response->json('usage.output_tokens_details.reasoning_tokens'),
            ]);
        });
    }

    private function registerSubtitleQueueTracing(): void
    {
        Queue::before(function (JobProcessing $event): void {
            app(SubtitleRuntimeTracer::class)->queueJobProcessing($event);
        });

        Queue::after(function (JobProcessed $event): void {
            app(SubtitleRuntimeTracer::class)->queueJobProcessed($event);
        });

        Queue::failing(function (JobFailed $event): void {
            app(SubtitleRuntimeTracer::class)->queueJobFailed($event);
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
