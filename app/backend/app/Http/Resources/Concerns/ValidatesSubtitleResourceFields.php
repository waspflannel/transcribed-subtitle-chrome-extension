<?php

namespace App\Http\Resources\Concerns;

use LogicException;

trait ValidatesSubtitleResourceFields
{
    protected function requiredEnrichmentMode(mixed $value): string
    {
        if ($value !== 'on_demand' && $value !== 'full') {
            throw new LogicException('Subtitle job has an invalid enrichment_mode.');
        }

        return $value;
    }

    protected function requiredBoolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new LogicException("Subtitle job is missing {$field}.");
        }

        return $value;
    }

    protected function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new LogicException("Subtitle job is missing {$field}.");
        }

        return $value;
    }

    protected function requiredProgressPercent(mixed $value): int
    {
        if (! is_int($value)) {
            throw new LogicException('Subtitle job is missing progress_percent.');
        }

        return $value;
    }
}
