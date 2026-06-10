<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class PostgresErrors
{
    public static function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23505', '23000'], true);
    }
}
