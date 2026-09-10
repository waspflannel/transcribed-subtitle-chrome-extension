<?php

namespace App\Support;

/**
 * Bump the relevant value whenever a processing change can affect generated
 * output. Each consumer includes its value in a durable reuse/cache key.
 */
final class SubtitleProcessingVersion
{
    public const JOB = 'scribe-v2-tokenizer-v10-async-';

    public const TRANSCRIPT_CACHE = 'transcript-chunks-v3';

    public const LEARNING_TOKEN_CACHE = 'learning-token-v8';

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
