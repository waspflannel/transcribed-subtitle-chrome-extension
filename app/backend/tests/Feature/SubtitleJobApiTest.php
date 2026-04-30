<?php

namespace Tests\Feature;

use App\Jobs\ProcessSubtitleJob;
use App\Models\SubtitleJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SubtitleJobApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_subtitle_job_validates_and_dispatches_processing(): void
    {
        Queue::fake();

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('progress.stage', 'queued');

        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'ar',
            'target_language' => 'en',
            'status' => 'queued',
        ]);

        Queue::assertPushed(ProcessSubtitleJob::class);
    }

    public function test_duplicate_subtitle_job_request_reuses_existing_job(): void
    {
        Queue::fake();

        $firstResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertAccepted();
        $secondResponse
            ->assertAccepted()
            ->assertJsonPath('jobId', $firstResponse->json('jobId'));

        $this->assertSame(1, SubtitleJob::count());
        Queue::assertPushed(ProcessSubtitleJob::class, 1);
    }

    public function test_job_status_route_returns_current_job_state(): void
    {
        $job = SubtitleJob::factory()->create([
            'public_id' => '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
        ]);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson("/v1/subtitle-jobs/{$job->public_id}")
            ->assertOk()
            ->assertJsonPath('jobId', $job->public_id)
            ->assertJsonPath('status', 'queued');
    }

    public function test_create_subtitle_job_returns_stable_validation_errors(): void
    {
        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', [
                'youtubeVideoId' => 'bad',
                'sourceLanguage' => 'fr',
                'targetLanguage' => 'en',
                'options' => [
                    'includeRomanization' => true,
                    'includeGloss' => true,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
    }

    public function test_api_requires_extension_install_id(): void
    {
        $this
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'videoDurationSeconds' => 213,
            'sourceLanguage' => 'ar',
            'targetLanguage' => 'en',
            'options' => [
                'includeRomanization' => true,
                'includeGloss' => true,
            ],
        ];
    }

    private function installId(): string
    {
        return 'install_'.str_repeat('a', 32);
    }
}
