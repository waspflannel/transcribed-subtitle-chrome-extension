<?php

namespace App\Support;

/**
 * Bump the relevant value whenever a processing change can affect generated
 * output. Each consumer includes its value in a durable reuse/cache key.
 */
final class SubtitleProcessingVersion
{
    public const JOB = 'scribe-v2-analysis-v16-';

    public const TRANSCRIPT_CACHE = 'transcript-chunks-v6';

    public const LEARNING_TOKEN_CACHE = 'learning-token-v10';

    public static function transcriptCacheModel(string $model): string
    {
        return $model.':'.self::TRANSCRIPT_CACHE;
    }

    public static function transcriptionOptionsHash(string $mode): string
    {
        return $mode === 'upload' ? '' : hash('sha256', $mode);
    }
}
