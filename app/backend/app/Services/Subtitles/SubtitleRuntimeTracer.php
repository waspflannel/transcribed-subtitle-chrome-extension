<?php

namespace App\Services\Subtitles;

use App\Jobs\EnrichSubtitleCueBatch;
use App\Jobs\FinalizeSubtitleJob;
use App\Jobs\MergeSubtitleCuesAfterRomanizationBatches;
use App\Jobs\PrepareSubtitleCuesAfterAnalysisBatches;
use App\Jobs\ProcessSubtitleJob;
use App\Jobs\RomanizeSubtitleCueBatch;
use App\Jobs\TokenizeSubtitleCueBatch;
use App\Jobs\TranslateSubtitleCueBatch;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubtitleRuntimeTracer
{
    /**
     * @var array<string, int>
     */
    private static array $queueStartedAtMs = [];

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
        'youtube_url',
    ];

    /**
     * @var array<int, class-string>
     */
    private const QUEUED_SUBTITLE_JOB_CLASSES = [
        EnrichSubtitleCueBatch::class,
        FinalizeSubtitleJob::class,
        MergeSubtitleCuesAfterRomanizationBatches::class,
        PrepareSubtitleCuesAfterAnalysisBatches::class,
        ProcessSubtitleJob::class,
        RomanizeSubtitleCueBatch::class,
        TokenizeSubtitleCueBatch::class,
        TranslateSubtitleCueBatch::class,
    ];

    /**
     * @param  array<string, mixed>  $context
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
            SubtitleJobEvent::query()->create($row);
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

    public function queueJobProcessing(JobProcessing $event): void
    {
        $context = $this->queueContext($event->connectionName, $event->job);

        if ($context === null) {
            return;
        }

        self::$queueStartedAtMs[$this->queueRuntimeKey($context)] = $this->currentTimeMs();

        $this->jobEventById(
            (int) $context['subtitle_job_id'],
            'queue.processing',
            $context,
            logName: 'backend.queue_job_processing',
        );
    }

    public function queueJobProcessed(JobProcessed $event): void
    {
        $context = $this->queueContext($event->connectionName, $event->job);

        if ($context === null) {
            return;
        }

        $runtimeKey = $this->queueRuntimeKey($context);
        $context['duration_ms'] = $this->durationMs(self::$queueStartedAtMs[$runtimeKey] ?? null);
        unset(self::$queueStartedAtMs[$runtimeKey]);

        $this->jobEventById(
            (int) $context['subtitle_job_id'],
            'queue.processed',
            $context,
            logName: 'backend.queue_job_processed',
        );
    }

    public function queueJobFailed(JobFailed $event): void
    {
        $context = $this->queueContext($event->connectionName, $event->job);

        if ($context === null) {
            return;
        }

        $runtimeKey = $this->queueRuntimeKey($context);
        $context['duration_ms'] = $this->durationMs(self::$queueStartedAtMs[$runtimeKey] ?? null);
        $previous = $event->exception->getPrevious();
        $context['exception'] = $event->exception::class;
        $context['previous_exception'] = $previous !== null ? $previous::class : null;
        unset(self::$queueStartedAtMs[$runtimeKey]);

        $this->jobEventById(
            (int) $context['subtitle_job_id'],
            'queue.failed',
            $context,
            'error',
            'backend.queue_job_failed',
        );
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
     * @return array<string, mixed>|null
     */
    private function queueContext(string $connectionName, mixed $queueJob): ?array
    {
        $payload = method_exists($queueJob, 'payload') ? $queueJob->payload() : [];
        $queue = method_exists($queueJob, 'getQueue') ? $queueJob->getQueue() : null;
        $queueName = is_string($queue) && $queue !== '' ? $queue : SubtitleQueue::name();

        if (! in_array($queueName, SubtitleQueue::names(), true)) {
            return null;
        }

        $command = $this->payloadCommand($payload);

        if (! is_object($command) || ! property_exists($command, 'subtitleJobId')) {
            return null;
        }

        if (! property_exists($command, 'runId') || ! is_string($command->runId)) {
            return null;
        }

        return [
            'subtitle_job_id' => (int) $command->subtitleJobId,
            'run_id' => $command->runId,
            'batch_index' => property_exists($command, 'batchIndex') && is_int($command->batchIndex) ? $command->batchIndex : null,
            'laravel_batch_id' => property_exists($command, 'batchId') && is_string($command->batchId) ? $command->batchId : null,
            'laravel_job_uuid' => is_string($payload['uuid'] ?? null) ? $payload['uuid'] : null,
            'job_class' => $command::class,
            'queue_connection' => $connectionName,
            'queue' => $queueName,
            'attempt' => method_exists($queueJob, 'attempts') ? $queueJob->attempts() : null,
            'worker_pid' => getmypid() ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadCommand(array $payload): mixed
    {
        $serialized = $payload['data']['command'] ?? null;
        $commandName = $payload['data']['commandName'] ?? $payload['displayName'] ?? null;

        if (
            ! is_string($serialized)
            || ! is_string($commandName)
            || ! in_array($commandName, self::QUEUED_SUBTITLE_JOB_CLASSES, true)
        ) {
            return null;
        }

        try {
            return unserialize($serialized, ['allowed_classes' => self::QUEUED_SUBTITLE_JOB_CLASSES]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function queueRuntimeKey(array $context): string
    {
        return (string) ($context['laravel_job_uuid'] ?? spl_object_id((object) $context));
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

    private function currentTimeMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function durationMs(?int $startedAtMs): ?int
    {
        if ($startedAtMs === null) {
            return null;
        }

        return max(0, $this->currentTimeMs() - $startedAtMs);
    }
}
