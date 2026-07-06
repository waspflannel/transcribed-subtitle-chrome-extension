<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContractResponseValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_and_track_responses_match_contract_schemas(): void
    {
        $installId = $this->installId();
        $user = User::factory()->create();

        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->postJson('/v1/subtitle-jobs', [
                    'youtubeVideoId' => 'create00001',
                    'youtubeUrl' => 'https://www.youtube.com/watch?v=create00001',
                    'videoDurationSeconds' => 120,
                    'sourceLanguage' => 'auto',
                    'targetLanguage' => 'eng',
                    'enrichmentMode' => 'on_demand',
                    'includeRomanization' => true,
                    'includeTranslation' => false,
                ])
                ->assertAccepted(),
            'job-response.schema.json',
        );

        $runningJob = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'install_id' => $installId,
            'youtube_video_id' => 'runvalid001',
            'youtube_url' => 'https://www.youtube.com/watch?v=runvalid001',
            'status' => 'running',
            'stage' => 'transcribing',
            'progress_percent' => 45,
        ]);

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->getJson('/v1/subtitle-jobs/'.$runningJob->public_id)
                ->assertOk(),
            'job-response.schema.json',
        );

        app(SubtitleJobArtifactStore::class)->putCueCollection($runningJob, SubtitleJobArtifactStore::DRAFT_CUES, [
            ['cueId' => 'cue-0001', 'index' => 0, 'startMs' => 500, 'endMs' => 2100, 'sourceText' => 'first transcript segment', 'translatedText' => '', 'tokens' => []],
        ]);

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->getJson('/v1/subtitle-jobs/'.$runningJob->public_id.'/partial-track')
                ->assertOk(),
            'partial-track-response.schema.json',
        );

        $completedTrack = $this->completedTrack($installId, $user, 'complete001');

        $completedResponse = $this
            ->withExtensionAuth($installId, $user)
            ->getJson('/v1/subtitle-jobs/'.$completedTrack->job->public_id)
            ->assertOk();

        $this->assertResponseMatchesSchema($completedResponse, 'job-response.schema.json');
        $this->assertPayloadMatchesSchema($completedResponse->json('track'), 'track-response.schema.json');

        $failedJob = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'install_id' => $installId,
            'youtube_video_id' => 'failvalid01',
            'youtube_url' => 'https://www.youtube.com/watch?v=failvalid01',
            'status' => 'failed',
            'stage' => 'transcribing',
            'progress_percent' => 20,
            'error_code' => 'transcription_failed',
            'error_message' => 'Transcription failed.',
        ]);

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->getJson('/v1/subtitle-jobs/'.$failedJob->public_id)
                ->assertOk(),
            'job-response.schema.json',
        );
    }

    public function test_history_learning_token_and_error_responses_match_contract_schemas(): void
    {
        $installId = $this->installId('b');
        $user = User::factory()->create();

        $this->completedTrack($installId, $user, 'history0001');
        SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'install_id' => $installId,
            'youtube_video_id' => 'history0002',
            'youtube_url' => 'https://www.youtube.com/watch?v=history0002',
            'status' => 'running',
            'stage' => 'tokenizing',
            'progress_percent' => 55,
        ]);
        SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'install_id' => $installId,
            'youtube_video_id' => 'history0003',
            'youtube_url' => 'https://www.youtube.com/watch?v=history0003',
            'status' => 'failed',
            'stage' => 'enriching',
            'progress_percent' => 75,
            'error_code' => 'rate_limited',
            'error_message' => 'Subtitle enrichment is temporarily rate limited.',
        ]);

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->getJson('/v1/subtitle-jobs')
                ->assertOk(),
            'subtitle-job-history-response.schema.json',
        );

        $trackWithLearningMetadata = $this->completedTrack($installId, $user, 'learntok001', [
            'source_language' => 'eng',
            'detected_source_language' => 'eng',
            'target_language' => 'eng',
        ]);

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->postJson('/v1/learning-tokens', [
                    'trackId' => $trackWithLearningMetadata->public_id,
                    'cueId' => 'cue-0001',
                    'tokenIndex' => 0,
                ])
                ->assertOk(),
            'learning-token-response.schema.json',
        );

        $this->assertResponseMatchesSchema(
            $this
                ->withExtensionAuth($installId, $user)
                ->getJson('/v1/subtitle-jobs/00000000-0000-4000-8000-000000000000')
                ->assertNotFound(),
            'api-error.schema.json',
        );
    }

    private function completedTrack(string $installId, User $user, string $videoId, array $overrides = []): SubtitleTrack
    {
        $sourceLanguage = $overrides['source_language'] ?? 'auto';
        $detectedSourceLanguage = $overrides['detected_source_language'] ?? 'spa';
        $targetLanguage = $overrides['target_language'] ?? 'eng';

        $job = SubtitleJob::factory()->create([
            'user_id' => $user->id,
            'install_id' => $installId,
            'youtube_video_id' => $videoId,
            'youtube_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'source_language' => $sourceLanguage,
            'detected_source_language' => $detectedSourceLanguage,
            'target_language' => $targetLanguage,
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'expires_at' => now()->addDays(30),
        ]);

        return SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => $videoId,
                'source_language' => $sourceLanguage,
                'detected_source_language' => $detectedSourceLanguage,
                'target_language' => $targetLanguage,
                'expires_at' => now()->addDays(30),
            ]);
    }

    private function assertResponseMatchesSchema(TestResponse $response, string $schemaFile): void
    {
        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertPayloadMatchesSchema($payload, $schemaFile);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadMatchesSchema(array $payload, string $schemaFile): void
    {
        $payloadPath = tempnam(sys_get_temp_dir(), 'contract-payload-');
        $this->assertIsString($payloadPath);

        try {
            file_put_contents(
                $payloadPath,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );

            $process = new Process(
                ['node', 'scripts/validate-payload.mjs', $schemaFile, $payloadPath],
                $this->contractsPath(),
            );
            $process->setTimeout(30);
            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                trim($process->getOutput().PHP_EOL.$process->getErrorOutput()),
            );
        } finally {
            if (is_file($payloadPath)) {
                unlink($payloadPath);
            }
        }
    }

    private function contractsPath(): string
    {
        return realpath(base_path('../..')).DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.'contracts';
    }

    private function installId(string $character = 'a'): string
    {
        return 'install_'.str_repeat($character, 32);
    }
}
