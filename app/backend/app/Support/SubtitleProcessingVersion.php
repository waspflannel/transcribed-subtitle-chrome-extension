<?php

namespace App\Support;

/**
 * Bump the relevant value whenever a processing change can affect generated
 * output. Each consumer includes its value in a durable reuse/cache key.
 */
final class SubtitleProcessingVersion
{
    public const JOB = 'scribe-v2-analysis-v15-';

    public const TRANSCRIPT_CACHE = 'transcript-chunks-v5-mixed-language';

    public const LEARNING_TOKEN_CACHE = 'learning-token-v10';

    public static function transcriptCacheModel(string $model): string
    {
        return $model.':'.self::TRANSCRIPT_CACHE;
    }

    /** @param array<int, string> $hints */
    public static function transcriptionOptionsHash(array $hints, string $mode): string
    {
        return $hints === [] && $mode === 'upload'
            ? '' : hash('sha256', json_encode([$mode, $hints], JSON_THROW_ON_ERROR));
    }
}
