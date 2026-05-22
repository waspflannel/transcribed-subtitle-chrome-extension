<?php

use App\Exceptions\SubtitleProcessingException;
use App\Http\Middleware\RequireExtensionInstallId;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$databaseIsLocked = static function (Throwable $exception): bool {
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        if ($current instanceof PDOException && str_contains($current->getMessage(), 'database is locked')) {
            return true;
        }

        if (str_contains($current->getMessage(), 'database is locked')) {
            return true;
        }
    }

    return false;
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(
            [AuthenticatesRequests::class, ThrottleRequests::class, ThrottleRequestsWithRedis::class],
            RequireExtensionInstallId::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) use ($databaseIsLocked): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                'unauthenticated',
                'A valid extension API token is required.',
                401,
                request: $request,
            );
        });

        $exceptions->render(function (MissingAbilityException|AuthorizationException|AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                'unauthorized',
                'The extension API token is not allowed to access this resource.',
                403,
                request: $request,
            );
        });

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

        $exceptions->render(function (QueryException $exception, Request $request) use ($databaseIsLocked) {
            if (! $request->is('v1/*') || ! $databaseIsLocked($exception)) {
                return null;
            }

            Log::warning('backend.proxy_queue_unavailable', [
                'request_id' => ApiErrorResponse::requestId($request),
                'reason' => 'database_locked',
            ]);

            return ApiErrorResponse::make(
                'queue_unavailable',
                'Subtitle queue storage was busy while processing. Retry generation after the current job finishes.',
                503,
                request: $request,
            );
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
