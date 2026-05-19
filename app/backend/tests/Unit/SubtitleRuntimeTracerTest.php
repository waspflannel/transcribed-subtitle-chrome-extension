<?php

namespace Tests\Unit;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobEvent;
use App\Services\Subtitles\SubtitleRuntimeTracer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubtitleRuntimeTracerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitized_context_omits_generated_text_prompts_secrets_and_payloads(): void
    {
        $context = app(SubtitleRuntimeTracer::class)->sanitizeContext([
            'stage' => 'tokenizing',
            'duration_ms' => 25,
            'token_count' => 3,
            'transcript' => 'raw transcript',
            'prompt' => 'provider prompt',
            'sourceText' => 'source text',
            'translatedText' => 'translated text',
            'tokens' => [['text' => 'secret token']],
            'romanization' => 'romanized content',
            'audio_path' => 'C:\\tmp\\audio.m4a',
            'youtube_url' => 'https://www.youtube.com/watch?v=secret',
            'file_path' => 'C:\\tmp\\artifact.json',
            'install_id' => 'install_secret',
            'api_key' => 'sk-secret',
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'nested' => ['not' => 'scalar'],
        ]);

        $this->assertSame([
            'stage' => 'tokenizing',
            'duration_ms' => 25,
            'token_count' => 3,
        ], $context);
    }

    public function test_record_persists_sanitized_event_context(): void
    {
        $job = SubtitleJob::factory()->create();

        app(SubtitleRuntimeTracer::class)->jobEvent($job, 'stage.completed', [
            'stage' => 'tokenizing',
            'duration_ms' => 44,
            'token_count' => 2,
            'prompt' => 'do not store',
            'sourceText' => 'do not store',
        ]);

        $event = SubtitleJobEvent::query()->where('event', 'stage.completed')->sole();

        $this->assertSame($job->id, $event->subtitle_job_id);
        $this->assertSame($job->public_id, $event->public_job_id);
        $this->assertSame($job->run_id, $event->run_id);
        $this->assertSame('tokenizing', $event->stage);
        $this->assertSame(44, $event->duration_ms);
        $this->assertSame(['token_count' => 2], $event->context);
    }
}
