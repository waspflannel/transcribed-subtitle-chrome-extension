<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubtitleRuntimeTracer
{
    /**
     * @var array<int, string>
     */
    private const COLUMN_KEYS = [
        'stage',
        'status',
        'queue_connection',
        'queue',
        'laravel_job_uuid',
        'laravel_batch_id',
        'batch_index',
        'worker_pid',
        'attempt',
        'duration_ms',
        'wait_ms',
        'error_code',
        'exception',
    ];

    /**
     * @var array<int, string>
     */
    private const UNSAFE_CONTEXT_KEYS = [
        'api_key',
        'access_token',
        'audio',
        'audio_path',
        'audio_url',
        'authorization',
        'bearer_token',
        'cue',
        'cues',
        'file_path',
        'install_id',
        'password',
        'prompt',
        'provider_payload',
        'provider_secret',
        'raw_provider_payload',
        'refresh_token',
        'romanization',
        'secret',
        'secret_key',
        'segments',
        'source_text',
        'sourceText',
        'token',
        'tokens',
        'transcript',
        'translated_text',
        'translatedText',
        'translation',
        'translations',
        'user_id',
        'youtube_url',
        'vocabularyHints',
        'vocabulary_hints',
        'keyterms',
    ];

    /**
     * @var array<int, class-string>
     */
    public function record(
        ?SubtitleJob $job,
        string $event,
        array $context = [],
        string $level = 'info',
        string $logName = 'backend.subtitle_trace_event',
    ): void {
        $safeContext = $this->sanitizeContext($context);
        $publicJobId = $job?->public_id ?? $this->stringValue($safeContext['public_job_id'] ?? null);
        $runId = $this->stringValue($safeContext['run_id'] ?? null) ?? $job?->run_id;

        $row = [
            'subtitle_job_id' => $job?->id,
            'public_job_id' => $publicJobId,
            'run_id' => $runId,
            'event' => $event,
            'context' => $this->eventContext($safeContext),
        ];

        foreach (self::COLUMN_KEYS as $key) {
            if (array_key_exists($key, $safeContext)) {
                $row[$key] = $safeContext[$key];
            }
        }

        try {
            DB::transaction(fn () => SubtitleJobEvent::query()->create($row));
        } catch (Throwable $exception) {
            Log::warning('backend.subtitle_trace_persistence_failed', [
                'event' => $event,
                'job_id' => $publicJobId,
                'exception' => $exception::class,
            ]);
        }

        Log::log($level, $logName, [
            'event' => $event,
            'job_id' => $publicJobId,
            'run_id' => $runId,
            ...$safeContext,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function jobEvent(
        SubtitleJob $job,
        string $event,
        array $context = [],
        string $level = 'info',
        string $logName = 'backend.subtitle_trace_event',
    ): void {
        $this->record($job, $event, $context, $level, $logName);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function jobEventById(
        int $subtitleJobId,
        string $event,
        array $context = [],
        string $level = 'info',
        string $logName = 'backend.subtitle_trace_event',
    ): void {
        $job = SubtitleJob::query()->find($subtitleJobId);

        if ($job === null) {
            return;
        }

        $this->jobEvent($job, $event, $context, $level, $logName);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! is_string($key) || $this->isUnsafeContextKey($key)) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function eventContext(array $context): array
    {
        return array_diff_key($context, array_flip([
            'public_job_id',
            'run_id',
            ...self::COLUMN_KEYS,
        ]));
    }

    private function isUnsafeContextKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $key) ?? $key);
        $unsafe = array_map(
            fn (string $unsafeKey): string => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $unsafeKey) ?? $unsafeKey),
            self::UNSAFE_CONTEXT_KEYS,
        );

        return in_array($normalized, $unsafe, true);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
