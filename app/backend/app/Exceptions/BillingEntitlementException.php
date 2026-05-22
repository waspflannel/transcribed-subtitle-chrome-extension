<?php

namespace App\Exceptions;

use RuntimeException;

class BillingEntitlementException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function paymentRequired(): self
    {
        return new self('payment_required', 'Choose an active billing plan before generating subtitles.', 402);
    }

    public static function usageExhausted(): self
    {
        return new self('usage_exhausted', 'This billing period does not have enough subtitle minutes remaining.', 402);
    }

    public static function featureUnavailable(): self
    {
        return new self('feature_unavailable', 'The selected billing plan does not include this generation option.', 403);
    }

    public static function concurrencyExceeded(): self
    {
        return new self('concurrency_exceeded', 'This billing plan already has the maximum number of running subtitle jobs.', 429);
    }
}
