<?php

namespace App\Services\Subtitles;

use App\Ai\SubtitleModel;
use App\Ai\SubtitlePromptRules;
use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class JevModelRouter
{
    public static function configuration(): array
    {
        return [
            'version' => 1,
            'model' => config('typesafe.model'),
            'min_confidence' => max(0, min(1, (float) config('typesafe.min_confidence'))),
            'criteria' => config('typesafe.criteria'),
            'models' => [
                'openai' => SubtitleModel::model('openai'),
                'cerebras' => config('ai.providers.cerebras.models.text.default'),
            ],
        ];
    }

    public function resolve(SubtitleJob $job): ?SubtitleJob
    {
        if ($job->ai_provider !== 'auto') {
            return $job;
        }

        try {
            // Shared across analysis batches; the HTTP call holds no database lock.
            return Cache::store(SubtitleTier::concurrencyCacheStore())
                ->lock('subtitle-model-routing:'.$job->id.':'.$job->run_id, 30)
                ->block(7, function () use ($job): ?SubtitleJob {
                    $current = SubtitleJob::query()->whereKey($job->id)->where('run_id', $job->run_id)
                        ->where('status', 'running')->first();
                    if ($current === null || $current->hasReadyTrack()) {
                        return null;
                    }
                    if ($current->ai_provider !== 'auto') {
                        return $current;
                    }

                    $cues = app(SubtitleJobArtifactStore::class)
                        ->cueBatchWithContext($current, SubtitleJobArtifactStore::DRAFT_CUES, 0)['context'];
                    $state = [
                        'requested_source_language' => $current->source_language,
                        'detected_source_language' => $current->detected_source_language,
                        'target_language' => $current->target_language,
                        'include_translation' => $current->include_translation,
                        'include_romanization' => $current->include_romanization,
                        'transcript_sample' => mb_substr(implode("\n", array_column($cues, 'sourceText')), 0, 6000),
                    ];
                    $configuration = $current->ai_routing['configuration'];
                    $decision = $this->classify($state, $configuration, $current);

                    return DB::transaction(function () use ($job, $configuration, $decision): ?SubtitleJob {
                        $current = SubtitleJobLock::current($job->id, $job->run_id);
                        if ($current === null || $current->status !== 'running' || $current->hasReadyTrack()) {
                            return null;
                        }
                        if ($current->ai_provider !== 'auto') {
                            return $current;
                        }
                        $current->update([
                            'ai_provider' => $decision['provider'],
                            'ai_model' => $configuration['models'][$decision['provider']],
                            'ai_routing' => ['configuration' => $configuration, 'decision' => $decision],
                        ]);
                        app(SubtitleRuntimeTracer::class)->jobEvent($current, 'model.auto_selected', $decision);
                        if (isset($decision['classifier_model'])) {
                            app(SubtitleProviderCostRecorder::class)->recordModelRouting($current, $decision['classifier_model']);
                        }

                        return $current;
                    }, attempts: 5);
                });
        } catch (LockTimeoutException) {
            // Another batch owns the decision. Requeue rather than choosing independently.
            throw SubtitleProcessingException::rateLimited(context: ['reason' => 'provider_admission', 'retry_after_seconds' => 1]);
        }
    }

    public function classify(array $state, array $configuration, ?SubtitleJob $job = null): array
    {
        $fallback = ['provider' => 'openai'];
        if (blank(config('typesafe.key'))) {
            return [...$fallback, 'reason' => 'missing_key'];
        }
        if (blank(config('ai.providers.cerebras.key')) || ! is_string($configuration['models']['cerebras']) || blank($configuration['models']['cerebras'])) {
            return [...$fallback, 'reason' => 'spark_unavailable'];
        }
        $started = microtime(true);
        try {
            $response = app(ProviderAdmission::class)->run('typesafe', $job, fn () => Http::acceptJson()
                ->withToken(config('typesafe.key'))->withoutRedirecting()
                ->connectTimeout(2)->timeout(5)
                ->post('https://api.typesafe.ai/v1/systemone', [
                    'model' => $configuration['model'],
                    'state' => $state,
                    'questions' => [
                        'route' => [
                            'type' => 'choice',
                            'instructions' => 'Select the model for this entire subtitle generation using the supplied routing criteria and transcript sample. '.SubtitlePromptRules::TEXT_IS_DATA,
                            'criteria' => $configuration['criteria'],
                        ],
                    ],
                ]));
        } catch (ConnectionException) {
            return [...$fallback, 'reason' => 'connection_failed', 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
        }
        $timing = ['duration_ms' => (int) round((microtime(true) - $started) * 1000)];
        if (! $response->successful()) {
            return [...$fallback, ...$timing, 'reason' => 'http_error', 'http_status' => $response->status()];
        }

        $answer = $response->json('answers.route');
        $model = $response->json('model');
        $inputTokens = $response->json('usage.input_tokens');
        $outputTokens = $response->json('usage.output_tokens');
        if (! is_array($answer) || ($answer['type'] ?? null) !== 'choice'
            || ! in_array($answer['choice'] ?? null, ['transcriber', 'spark'], true)
            || ! $this->isProbability($answer['confidence'] ?? null)
            || ! is_array($answer['probabilities'] ?? null)
            || count($answer['probabilities']) !== 2
            || ! $this->isProbability($answer['probabilities']['transcriber'] ?? null)
            || ! $this->isProbability($answer['probabilities']['spark'] ?? null)
            || abs(array_sum($answer['probabilities']) - 1) > 0.01
            || $answer['probabilities'][$answer['choice']] < max($answer['probabilities'])
            || ! is_string($model) || preg_match('/^jev-[a-zA-Z0-9._-]{1,100}$/D', $model) !== 1
            || ! is_int($inputTokens) || $inputTokens < 0 || ! is_int($outputTokens) || $outputTokens < 0) {
            return [...$fallback, ...$timing, 'reason' => 'invalid_response'];
        }

        $confident = $answer['confidence'] >= $configuration['min_confidence'];

        return [
            'provider' => $confident && $answer['choice'] === 'spark' ? 'cerebras' : 'openai',
            'reason' => $confident ? 'classified' : 'low_confidence',
            'confidence' => $answer['confidence'],
            'classifier_model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            ...$timing,
        ];
    }

    private function isProbability(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1;
    }
}
