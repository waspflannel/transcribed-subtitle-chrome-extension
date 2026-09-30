<?php

namespace Tests\Feature;

use App\Models\InstanceSetting;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use App\Models\SubtitleTrack;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemoveAutomaticModelRoutingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_saved_models_tracks_and_other_settings_while_removing_routing(): void
    {
        $migration = require database_path('migrations/2026_09_30_201713_remove_automatic_model_routing_from_subtitle_jobs.php');
        $migration->down();
        $savedSettings = ['providers' => [
            'openai' => ['apiKey' => 'keep-openai-key', 'model' => 'keep-openai-model'],
            'cerebras' => ['apiKey' => 'keep-cerebras-key', 'model' => 'saved-model'],
            'elevenlabs' => ['apiKey' => 'keep-eleven-key'],
            'typesafe' => ['apiKey' => 'remove-router-key', 'model' => 'old-router-model'],
        ], 'retentionDays' => 45];
        InstanceSetting::create(['id' => 1, 'values' => $savedSettings]);
        $manual = $this->savedJob('manual');
        $resolved = $this->savedJob('automatic');
        $originalRun = $resolved->run_id;
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $resolved->id]);
        $originalTrack = $track->refresh()->getAttributes();

        $migration->up();

        $this->assertFalse(Schema::hasColumn('subtitle_jobs', 'ai_selection_key'));
        $this->assertFalse(Schema::hasColumn('subtitle_jobs', 'ai_routing'));
        foreach ([$manual, $resolved] as $job) {
            $this->assertSame('cerebras', $job->refresh()->ai_provider);
            $this->assertSame('saved-model', $job->ai_model);
            $this->assertSame('completed', $job->status);
            $this->assertNull($job->reuse_key);
        }
        $this->assertSame($originalRun, $resolved->run_id);
        $this->assertSame($originalTrack, $track->refresh()->getAttributes());
        unset($savedSettings['providers']['typesafe']);
        $this->assertSame($savedSettings, InstanceSetting::findOrFail(1)->values);
        $this->assertStringNotContainsString('keep-openai-key', DB::table('instance_settings')->value('values'));

        // Old automatic and manual rows remain saved; the completed track is reusable manually.
        Queue::fake();
        config(['ai.providers.cerebras.models.text.default' => 'saved-model']);
        $payload = [
            'youtubeVideoId' => $resolved->youtube_video_id, 'youtubeUrl' => $resolved->youtube_url,
            'sourceLanguage' => 'auto', 'targetLanguage' => 'eng', 'aiProvider' => 'cerebras',
            'includeRomanization' => false, 'includeTranslation' => false,
        ];
        $reused = app(SubtitleJobService::class)->generate($payload, 'install_migration_test');
        $this->assertSame($resolved->id, $reused->id);
        $this->assertSame($track->id, $reused->track->id);
        $this->assertDatabaseCount('subtitle_jobs', 2);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_upgrade_pins_unresolved_jobs_and_fences_old_work_without_provider_calls(): void
    {
        $this->travelTo(now()->startOfSecond());
        $migration = require database_path('migrations/2026_09_30_201713_remove_automatic_model_routing_from_subtitle_jobs.php');
        $migration->down();
        config(['ai.providers.openai.models.text.default' => 'configured-fallback']);
        config(['subtitles.youtube.temp_directory' => storage_path('retired-routing-audio')]);
        $jobs = [];
        foreach (['queued', 'running', 'cancelled', 'failed'] as $status) {
            $job = SubtitleJob::factory()->create(['status' => $status, 'ai_provider' => 'auto', 'ai_model' => 'pending']);
            DB::table('subtitle_jobs')->where('id', $job->id)->update([
                'ai_selection_key' => 'old-automatic-key',
                'ai_routing' => $status === 'failed' ? null : json_encode(['configuration' => ['models' => ['openai' => 'pinned-fallback']]]),
            ]);
            SubtitleJobArtifact::create([
                'subtitle_job_id' => $job->id, 'run_id' => $job->run_id,
                'artifact_type' => 'draft_cues', 'batch_index' => -1, 'payload' => ['private' => 'transcript'],
            ]);
            File::ensureDirectoryExists(SubtitleAudioWorkspace::directory($job->run_id));
            File::put(SubtitleAudioWorkspace::directory($job->run_id).DIRECTORY_SEPARATOR.'audio.flac', 'test-audio');
            $jobs[] = [$job, $job->run_id, $status];
        }

        DB::transaction(function () use ($migration, $jobs): void {
            $migration->up();
            foreach ($jobs as [, $oldRun]) {
                $this->assertDirectoryExists(SubtitleAudioWorkspace::directory($oldRun));
            }
        });

        foreach ($jobs as [$job, $oldRun, $oldStatus]) {
            $job->refresh();
            $this->assertSame('openai', $job->ai_provider);
            $this->assertSame($oldStatus === 'failed' ? 'configured-fallback' : 'pinned-fallback', $job->ai_model);
            $this->assertNotSame($oldRun, $job->run_id);
            $this->assertDirectoryDoesNotExist(SubtitleAudioWorkspace::directory($oldRun));
            $this->assertSame($oldStatus === 'failed' ? 'failed' : 'cancelled', $job->status);
            if (in_array($oldStatus, ['queued', 'running'], true)) {
                $this->assertSame('generation_cancelled', $job->error_code);
                $this->assertTrue($job->expires_at->equalTo(now()->addDays(30)));
            }
        }
        $this->assertDatabaseCount('subtitle_jobs', 4);
        $this->assertDatabaseCount('subtitle_job_artifacts', 0);
        Http::assertNothingSent();
    }

    private function savedJob(string $selection): SubtitleJob
    {
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ', 'status' => 'completed',
            'ai_provider' => 'cerebras', 'ai_model' => 'saved-model',
            'processing_version' => SubtitleJobService::processingVersionFor(false, false),
            'include_romanization' => false, 'reuse_key' => hash('sha256', $selection),
        ]);
        DB::table('subtitle_jobs')->where('id', $job->id)->update([
            'ai_selection_key' => $selection,
            'ai_routing' => $selection === 'manual' ? null : json_encode(['decision' => ['provider' => 'cerebras']]),
        ]);

        return $job;
    }
}
