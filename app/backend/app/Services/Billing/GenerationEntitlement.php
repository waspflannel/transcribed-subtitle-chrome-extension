<?php

namespace App\Services\Billing;

final readonly class GenerationEntitlement
{
    public function __construct(
        public string $planCode,
        public string $generationTier,
        public int $reservationMinutes,
        public bool $startImmediately,
    ) {}
}
