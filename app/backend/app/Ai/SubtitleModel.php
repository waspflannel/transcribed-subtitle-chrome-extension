<?php

namespace App\Ai;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\Codex\CodexService;

final class SubtitleModel
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly bool $fastMode = false,
    ) {}

    public static function configured(?string $provider = null, ?string $model = null, bool $fastMode = false): self
    {
        $provider = self::provider($provider);
        if ($provider === 'codex') {
            app(CodexService::class)->validateSelection($model ?? '', $fastMode);

            return new self($provider, $model, $fastMode);
        }
        if ($model !== null || $fastMode) {
            throw new SubtitleProcessingException('validation_failed', 'Choose Codex to select a model or fast mode.', 422);
        }

        return new self($provider, self::model($provider));
    }

    public static function forJob(SubtitleJob $job): self
    {
        return new self($job->ai_provider, $job->ai_model, (bool) $job->ai_fast_mode);
    }

    public static function forCorrection(SubtitleTrackLyricsCorrection $correction): self
    {
        if ($correction->ai_provider === null) {
            return self::forJob($correction->track->job);
        }

        return new self($correction->ai_provider, $correction->ai_model, (bool) $correction->ai_fast_mode);
    }

    public static function provider(?string $provider = null): string
    {
        $provider ??= config('ai.default');
        if (! in_array($provider, ['openai', 'cerebras', 'codex'], true)) {
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
