<?php

use App\Exceptions\SubtitleProcessingException;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                'validation_failed',
                'Request validation failed.',
                422,
                ['errors' => $exception->errors()],
                $request,
            );
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            $installId = $request->header('X-Extension-Install-Id');

            Log::warning('backend.proxy_rate_limited', [
                'request_id' => ApiErrorResponse::requestId($request),
                'ip' => $request->ip(),
                'install_id_hash' => is_string($installId) ? substr(hash('sha256', $installId), 0, 16) : null,
            ]);

            $response = ApiErrorResponse::make('rate_limited', 'Too many requests.', 429, request: $request);

            foreach ($exception->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ApiErrorResponse::make('not_found', 'Resource not found.', 404, request: $request);
        });

        $exceptions->render(function (SubtitleProcessingException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ApiErrorResponse::make($exception->publicCode, $exception->getMessage(), $exception->status, request: $request);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            Log::error('backend.proxy_internal_error', [
                'request_id' => ApiErrorResponse::requestId($request),
                'exception' => $exception::class,
            ]);

            return ApiErrorResponse::make('internal_error', 'Unexpected backend error.', 500, request: $request);
        });
    })->create();
