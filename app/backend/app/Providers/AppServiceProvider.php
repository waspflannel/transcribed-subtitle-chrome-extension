<?php

namespace App\Providers;

use App\Services\InstanceSettings;
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
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\GroqProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['translator']->addJsonPath(base_path('../../packages/localization/website'));
        // Exception traces must not retain prompt/response arguments in logs or failed_jobs.
        ini_set('zend.exception_ignore_args', '1');

        Ai::extend('cerebras', fn ($app, array $config): GroqProvider => new GroqProvider($config, $app->make(Dispatcher::class)));

        JsonResource::withoutWrapping();
        $this->registerSubtitleQueueTracing();
        $this->registerOpenAiResponseTracing();

        RateLimiter::for('subtitle-prefetch', fn (Request $request): array => [
            Limit::perMinute(6)->by('prefetch-install:'.$this->validatedInstallId($request, 'subtitle-prefetch')),
            Limit::perMinute(30)->by('prefetch-ip:'.$request->ip()),
        ]);

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

    private function registerOpenAiResponseTracing(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $url = rtrim(config('ai.providers.openai.url') ?? 'https://api.openai.com/v1', '/').'/responses';

            if ($event->request->url() !== $url || ($event->request['stream'] ?? false) === true) {
                return;
            }

            $stats = $event->response->handlerStats();
            $milliseconds = static fn (mixed $seconds): ?int => is_numeric($seconds) ? (int) round((float) $seconds * 1000) : null;
            $number = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;
            $allowed = static fn (mixed $value, array $values): ?string => in_array($value, $values, true) ? $value : null;
            $requestId = $event->response->header('x-request-id');

            // Select diagnostics explicitly: never log request/response bodies,
            // authorization headers, prompts, or generated text.
            Log::info('backend.openai_response_received', [
                'worker_pid' => getmypid() ?: null,
                'request_id' => is_string($requestId) && preg_match('/\Areq_[A-Za-z0-9_-]{1,100}\z/D', $requestId) === 1 ? $requestId : null,
                'model' => $event->request['model'] ?? null,
                'requested_service_tier' => $event->request['service_tier'] ?? null,
                'served_service_tier' => $allowed($event->response->json('service_tier'), ['auto', 'default', 'flex', 'scale', 'priority', 'fast']),
                'reasoning_effort' => data_get($event->request->data(), 'reasoning.effort'),
                'http_status' => $event->response->status(),
                'response_status' => $allowed($event->response->json('status'), ['completed', 'failed', 'in_progress', 'cancelled', 'queued', 'incomplete']),
                'incomplete_reason' => $allowed($event->response->json('incomplete_details.reason'), ['max_output_tokens', 'content_filter']),
                'error_code' => $allowed($event->response->json('error.code'), ['credit_balance_exhausted', 'insufficient_quota', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded', 'rate_limit_exceeded', 'server_error', 'invalid_request_error']),
                'duration_ms' => $milliseconds($stats['total_time'] ?? null),
                'connect_ms' => $milliseconds($stats['connect_time'] ?? null),
                'time_to_first_byte_ms' => $milliseconds($stats['starttransfer_time'] ?? null),
                'provider_processing_ms' => is_numeric($event->response->header('openai-processing-ms'))
                    ? (int) $event->response->header('openai-processing-ms') : null,
                'input_tokens' => $number($event->response->json('usage.input_tokens')),
                'cached_input_tokens' => $number($event->response->json('usage.input_tokens_details.cached_tokens')),
                'output_tokens' => $number($event->response->json('usage.output_tokens')),
                'reasoning_tokens' => $number($event->response->json('usage.output_tokens_details.reasoning_tokens')),
            ]);
        });
    }

    private function registerSubtitleQueueTracing(): void
    {
        Queue::before(function (JobProcessing $event): void {
            app(InstanceSettings::class)->apply();
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
