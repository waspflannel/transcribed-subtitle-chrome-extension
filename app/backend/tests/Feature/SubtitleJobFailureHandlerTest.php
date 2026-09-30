<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SubtitleJobFailureHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fail_job_claims_failure_atomically_and_only_one_callback_finalizes(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
            'video_duration_seconds' => 120,
        ]);

        $handler = app(SubtitleJobFailureHandler::class);
        $exception = SubtitleProcessingException::enrichmentFailed(context: [
            'reason' => 'token_text_not_in_source',
            'cue_index' => 12,
            'token_position' => 3,
            'prompt' => 'private prompt',
            'tokens' => [['text' => 'private generated token']],
            'provider_payload' => ['private' => 'response'],
        ]);

        $handler->failJob($job->id, 'tokenizing', $exception, $job->run_id);
        $handler->failJob($job->id, 'tokenizing', $exception, $job->run_id);

        $refreshed = $job->refresh();
        $this->assertSame('failed', $refreshed->status);
        $this->assertSame('enrichment_failed', $refreshed->error_code);
        $this->assertSame('tokenizing', $refreshed->stage);

        $failureTelemetryCount = (int) SubtitleJobEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('event', 'job.failed')
            ->count();
        $this->assertSame(1, $failureTelemetryCount, 'Only the winning callback should emit failure telemetry.');
        $failure = SubtitleJobEvent::query()->where('subtitle_job_id', $job->id)->where('event', 'job.failed')->firstOrFail();
        $this->assertSame('token_text_not_in_source', $failure->context['reason']);
        $this->assertSame(12, $failure->context['cue_index']);
        $this->assertSame(3, $failure->context['token_position']);
        $this->assertStringNotContainsString('private', json_encode($failure->context));

    }

    public function test_fail_job_with_stale_run_id_does_not_mark_job_failed(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'running',
            'stage' => 'tokenizing',
        ]);
        $staleRunId = (string) Str::uuid();

        app(SubtitleJobFailureHandler::class)->failJob(
            $job->id,
            'tokenizing',
            SubtitleProcessingException::enrichmentFailed(),
            $staleRunId,
        );

        $this->assertSame('running', $job->refresh()->status);
        $this->assertDatabaseHas('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.stale_run_skipped',
            'stage' => 'tokenizing',
        ]);
        $this->assertDatabaseMissing('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.failed',
        ]);
    }

    public function test_queue_publication_failure_marks_the_job_failed(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'running', 'stage' => 'preparing']);

        $settled = app(SubtitleJobFailureHandler::class)->failJob(
            subtitleJobId: $job->id,
            stage: 'preparing',
            exception: SubtitleProcessingException::queuePublicationFailed(),
            runId: $job->run_id,
        );

        $this->assertTrue($settled);
        $this->assertSame('failed', $job->refresh()->status);
        $this->assertSame('queue_publication_failed', $job->error_code);
    }

    public function test_fail_job_does_not_overwrite_already_completed_job(): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'error_code' => null,
            'error_message' => null,
        ]);

        app(SubtitleJobFailureHandler::class)->failJob(
            $job->id,
            'tokenizing',
            SubtitleProcessingException::enrichmentFailed(),
            $job->run_id,
        );

        $refreshed = $job->refresh();
        $this->assertSame('completed', $refreshed->status);
        $this->assertSame('finalizing', $refreshed->stage);
        $this->assertNull($refreshed->error_code);
        $this->assertNull($refreshed->error_message);
        $this->assertDatabaseMissing('subtitle_job_events', [
            'subtitle_job_id' => $job->id,
            'event' => 'job.failed',
        ]);
    }

    public function test_cleanup_database_failure_rolls_back_status(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'running']);
        $this->mock(SubtitleJobArtifactStore::class)
            ->shouldReceive('deleteForJob')->once()->andThrow(new RuntimeException('cleanup failed'));

        try {
            app(SubtitleJobFailureHandler::class)->failJob($job->id, 'tokenizing', SubtitleProcessingException::enrichmentFailed(), $job->run_id);
            $this->fail('Expected cleanup failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cleanup failed', $exception->getMessage());
        }

        $this->assertSame('running', $job->refresh()->status);
    }
}
