<?php

namespace App\Support;

/**
 * Bump the relevant value whenever a processing change can affect generated
 * output. Each consumer includes its value in a durable reuse/cache key.
 */
final class SubtitleProcessingVersion
{
    public const JOB = 'scribe-v2-tokenizer-v9-async-';

    public const TRANSCRIPT_CACHE = 'transcript-chunks-v2';

    public const LEARNING_TOKEN_CACHE = 'learning-token-v8';

    public static function transcriptCacheModel(string $model): string
    {
        return $model.':'.self::TRANSCRIPT_CACHE;
    }
}
