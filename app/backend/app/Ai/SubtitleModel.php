<?php

namespace App\Ai;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;

final class SubtitleModel
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
    ) {}

    public static function configured(?string $provider = null): self
    {
        $provider = self::provider($provider);

        return new self($provider, self::model($provider));
    }

    public static function forJob(SubtitleJob $job): self
    {
        return new self($job->ai_provider, $job->ai_model);
    }

    public static function provider(?string $provider = null): string
    {
        $provider ??= config('ai.default');
        if (! in_array($provider, ['openai', 'cerebras'], true)) {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI provider is not configured.');
        }

        return $provider;
    }

    public static function model(?string $provider = null): string
    {
        $model = config('ai.providers.'.self::provider($provider).'.models.text.default');
        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.');
        }

        return trim($model);
    }
}
