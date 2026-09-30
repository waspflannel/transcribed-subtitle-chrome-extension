<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

final class ProviderExceptionPolicy
{
    private const QUOTA_CODES = ['credit_balance_exhausted', 'insufficient_quota', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded'];

    public static function classify(Throwable $exception, array $context = []): SubtitleProcessingException
    {
        if ($exception instanceof SubtitleProcessingException) {
            return $exception;
        }

        $context = [...$context, ...self::diagnostics($exception)];
        if (isset($context['provider_error_code'])) {
            return SubtitleProcessingException::enrichmentFailed('Subtitle AI provider quota is exhausted.', [...$context, 'reason' => 'provider_quota_exhausted']);
        }
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RateLimitedException || ($cause instanceof RequestException && $cause->response->status() === 429)) {
                return SubtitleProcessingException::rateLimited('Subtitle AI processing is temporarily rate limited.', $context);
            }
            if ($cause instanceof ProviderOverloadedException || $cause instanceof ConnectionException
                || ($cause instanceof RequestException && $cause->response->serverError())) {
                return SubtitleProcessingException::providerUnavailable(context: [...$context, 'reason' => $cause instanceof ConnectionException ? 'connection_failure' : 'provider_overloaded']);
            }
        }

        return SubtitleProcessingException::enrichmentFailed('Subtitle AI processing failed.', $context);
    }

    /** Extract only trusted types, status numbers, and explicitly allowed provider codes. */
    public static function diagnostics(Throwable $exception): array
    {
        $context = ['exception' => $exception::class];
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (! $cause instanceof RequestException) {
                continue;
            }
            $context['status'] = $cause->response->status();
            $context['cause_exception'] = $cause::class;
            $requestId = $cause->response->header('x-request-id');
            if (is_string($requestId) && preg_match('/\Areq_[A-Za-z0-9_-]{1,100}\z/D', $requestId) === 1) {
                $context['provider_request_id'] = $requestId;
            }
            $code = $cause->response->json('error.code');
            if (in_array($code, self::QUOTA_CODES, true)) {
                $context['provider_error_code'] = $code;
            } elseif ($cause->response->json('error.type') === 'insufficient_quota') {
                $context['provider_error_code'] = 'insufficient_quota';
            }
        }

        return $context;
    }
}
