<?php

namespace App\Exceptions;

use RuntimeException;

final class StripeCheckoutPendingException extends RuntimeException
{
    /** @param array<string, string> $replacements */
    public function __construct(string $message, public readonly array $replacements = [])
    {
        parent::__construct($message);
    }
}
