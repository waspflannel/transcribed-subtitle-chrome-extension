<?php

namespace App\Ai;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrackLyricsCorrection;
use App\Services\ClaudeCode\ClaudeCodeService;
use App\Services\Codex\CodexService;
use Illuminate\Validation\Rule;

final class SubtitleModel
{
    public const PROVIDERS = ['openai', 'cerebras', 'codex', 'claude', 'anthropic'];

    /** Claude API (Anthropic API key) models. */
    public const CLAUDE_API_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1', 'claude-opus-5', 'claude-opus-4-8', 'claude-sonnet-5'];

    /** Anthropic fast mode exists only on these models, and only on the Claude API. */
    public const CLAUDE_FAST_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-opus-4-8'];

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
        if ($provider === 'claude') {
            if (! in_array($model, ClaudeCodeService::MODELS, true) || $fastMode) {
                throw new SubtitleProcessingException('validation_failed', 'Choose a Claude model: opus, sonnet or haiku.', 422);
            }

            return new self($provider, $model);
        }
        if ($provider === 'anthropic') {
            if (! in_array($model, self::CLAUDE_API_MODELS, true) || ($fastMode && ! in_array($model, self::CLAUDE_FAST_MODELS, true))) {
                throw new SubtitleProcessingException('validation_failed', 'Choose a Claude API model. Fast mode works only with Opus 5.5, Opus 5 and Opus 4.8.', 422);
            }

            return new self($provider, $model, $fastMode);
        }
        if ($model !== null || $fastMode) {
            throw new SubtitleProcessingException('validation_failed', 'Choose Codex or Claude to select a model, and Codex or Claude API for fast mode.', 422);
        }

        return new self($provider, self::model($provider));
    }

    /** Request rules for aiProvider, aiModel and aiFastMode, shared by generation and lyrics correction. */
    public static function requestRules(mixed $provider, mixed $model, mixed $fastMode): array
    {
        $models = match ($provider) {
            'claude' => ClaudeCodeService::MODELS,
            'anthropic' => $fastMode === true ? self::CLAUDE_FAST_MODELS : self::CLAUDE_API_MODELS,
            default => null,
        };
        $chooses = in_array($provider, ['codex', 'claude', 'anthropic'], true);
        $fast = in_array($provider, ['codex', 'anthropic'], true);

        return [
            'aiProvider' => ['sometimes', 'string', Rule::in(self::PROVIDERS)],
            'aiModel' => [Rule::requiredIf($chooses), Rule::prohibitedIf(! $chooses), 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', ...($models ? [Rule::in($models)] : [])],
            'aiFastMode' => ['sometimes', 'boolean:strict', Rule::prohibitedIf(! $fast && $fastMode !== false)],
        ];
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
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI provider is not configured.');
        }

        return $provider;
    }

    public static function adapter(string $provider): string
    {
        return match ($provider) {
            'codex' => 'codex-app-server',
            'claude' => 'claude-code-cli',
            default => 'laravel-ai-sdk',
        };
    }

    public static function model(?string $provider = null): string
    {
        $provider = self::provider($provider);
        $model = config('ai.providers.'.$provider.'.models.text.default');
        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.');
        }

        return trim($model);
    }
}
