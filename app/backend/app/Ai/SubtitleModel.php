<?php

namespace App\Ai;

use App\Exceptions\SubtitleProcessingException;

final class SubtitleModel
{
    public static function provider(): string
    {
        $provider = config('ai.default');
        if (! in_array($provider, ['openai', 'cerebras'], true)) {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI provider is not configured.');
        }

        return $provider;
    }

    public static function model(): string
    {
        $model = config('ai.providers.'.self::provider().'.models.text.default');
        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.');
        }

        return trim($model);
    }
}
