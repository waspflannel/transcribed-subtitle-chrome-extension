<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Audio\YouTubeAudioSource;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\Transcription\TranscriptionService;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleJobApiTest extends TestCase
{
    use RefreshDatabase;

    private RecordingYouTubeAudioSource $audioSource;

    private RecordingTranscriptionService $transcriptionService;

    private RecordingTranslationAnalysisProvider $translationAnalysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audioSource = new RecordingYouTubeAudioSource;
        $this->transcriptionService = new RecordingTranscriptionService;
        $this->translationAnalysis = new RecordingTranslationAnalysisProvider;

        $this->app->instance(YouTubeAudioSource::class, $this->audioSource);
        $this->app->instance(TranscriptionService::class, $this->transcriptionService);
        $this->app->instance(LaravelAiTranslationAnalysisProvider::class, $this->translationAnalysis);
    }

    public function test_default_generation_returns_transcript_first_track_without_full_card_enrichment(): void
    {
        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('track.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('track.cues.0.startMs', 500)
            ->assertJsonPath('track.cues.0.endMs', 2100)
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.translatedText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.text', 'first')
            ->assertJsonPath('track.cues.0.tokens.0.normalizedText', 'first')
            ->assertJsonPath('track.webVtt', $this->sampleWebVtt())
            ->assertJsonStructure($this->completedJobShape());

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(1, SubtitleTrack::count());
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'spa',
            'target_language' => 'eng',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
        ]);
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
    }

    public function test_non_latin_transcript_first_generation_adds_best_effort_romanization(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'مرحبا بكم')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nمرحبا بكم\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'مرحبا بكم')
            ->assertJsonPath('track.cues.0.translatedText', 'مرحبا بكم')
            ->assertJsonPath('track.cues.0.romanization', 'romanized مرحبا بكم')
            ->assertJsonPath('track.cues.0.tokens.0.text', 'مرحبا')
            ->assertJsonPath('track.cues.0.tokens.0.romanization', 'romanized مرحبا')
            ->assertJsonPath('track.cues.0.tokens.1.text', 'بكم')
            ->assertJsonPath('track.cues.0.tokens.1.romanization', 'romanized بكم');

        $this->assertNull($response->json('track.cues.0.tokens.0.gloss'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
    }

    public function test_transcript_first_generation_skips_romanization_when_disabled(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'مرحبا بكم')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nمرحبا بكم\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'noroman0001',
                'includeRomanization' => false,
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'مرحبا بكم')
            ->assertJsonPath('track.cues.0.tokens.0.text', 'مرحبا');

        $this->assertNull($response->json('track.cues.0.romanization'));
        $this->assertNull($response->json('track.cues.0.tokens.0.romanization'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->romanizationCalls);
    }

    public function test_japanese_transcript_first_generation_rebuilds_grouped_tokens_and_romanization(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, '私は日本語を勉強しています')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\n私は日本語を勉強しています\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpn00000001',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', '私は日本語を勉強しています')
            ->assertJsonPath('track.cues.0.romanization', 'watashi wa nihongo o benkyo shite imasu')
            ->assertJsonPath('track.cues.0.tokens.0.text', '私')
            ->assertJsonPath('track.cues.0.tokens.2.text', '日本語')
            ->assertJsonPath('track.cues.0.tokens.2.romanization', 'nihongo')
            ->assertJsonPath('track.cues.0.tokens.4.text', '勉強しています');

        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_non_latin_transcript_first_generation_continues_when_romanization_fails(): void
    {
        $this->translationAnalysis->romanizationShouldFail = true;
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'مرحبا بكم')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nمرحبا بكم\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'nonlatin001']));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'مرحبا بكم')
            ->assertJsonPath('track.cues.0.tokens.0.text', 'مرحبا');

        $this->assertNull($response->json('track.cues.0.romanization'));
        $this->assertNull($response->json('track.cues.0.tokens.0.romanization'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'nonlatin001',
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);
    }

    public function test_transcript_first_generation_continues_with_transcript_only_cues_when_tokenization_fails(): void
    {
        $this->translationAnalysis->tokenizationShouldFail = true;

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'tokfail0001']));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.tokens', []);

        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'tokfail0001',
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);
    }

    public function test_japanese_romanization_failure_keeps_tokenizer_tokens(): void
    {
        $this->translationAnalysis->romanizationShouldFail = true;
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, '私 は 日 本 語 を 勉 強 し て い ま す')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\n私 は 日 本 語 を 勉 強 し て い ま す\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0001',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.tokens.0.text', '私')
            ->assertJsonPath('track.cues.0.tokens.2.text', '日本語')
            ->assertJsonPath('track.cues.0.tokens.4.text', '勉強しています');

        $this->assertNull($response->json('track.cues.0.romanization'));
        $this->assertNull($response->json('track.cues.0.tokens.0.romanization'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
    }

    public function test_japanese_romanization_failure_keeps_no_space_tokenizer_boundaries(): void
    {
        $this->translationAnalysis->romanizationShouldFail = true;
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'ねえ今思っていてあそうじゃな')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nねえ今思っていてあそうじゃな\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfail0002',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.tokens.0.text', 'ねえ')
            ->assertJsonPath('track.cues.0.tokens.1.text', '今')
            ->assertJsonPath('track.cues.0.tokens.2.text', '思っていて')
            ->assertJsonPath('track.cues.0.tokens.3.text', 'あそうじゃな');

        $this->assertNull($response->json('track.cues.0.romanization'));
        $this->assertNull($response->json('track.cues.0.tokens.0.romanization'));
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(1, $this->translationAnalysis->romanizationCalls);
    }

    public function test_full_enrichment_mode_blocks_for_all_card_metadata(): void
    {
        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.translatedText', 'Translated first transcript segment')
            ->assertJsonPath('track.cues.0.tokens.0.gloss', 'first');

        $this->assertSame(1, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(['spa', 'spa'], $this->translationAnalysis->sourceLanguages);
        $this->assertSame(['eng'], $this->translationAnalysis->targetLanguages);
    }

    public function test_full_japanese_enrichment_uses_grouped_token_boundaries(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, '私は日本語を勉強しています')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\n私は日本語を勉強しています\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'jpn',
                'youtubeVideoId' => 'jpnfull0001',
                'enrichmentMode' => 'full',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.translatedText', 'Translated 私は日本語を勉強しています')
            ->assertJsonPath('track.cues.0.tokens.2.text', '日本語')
            ->assertJsonPath('track.cues.0.tokens.2.gloss', 'Japanese language')
            ->assertJsonPath('track.cues.0.tokens.4.text', '勉強しています');

        $this->assertSame(1, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_full_same_language_generation_skips_translation_enrichment(): void
    {
        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'eng',
                'targetLanguage' => 'eng',
                'enrichmentMode' => 'full',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('track.cues.0.sourceText', 'first transcript segment')
            ->assertJsonPath('track.cues.0.translatedText', 'first transcript segment');

        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
        $this->assertSame(0, $this->translationAnalysis->tokenCalls);
    }

    public function test_full_enrichment_and_on_demand_tracks_are_cached_separately(): void
    {
        $onDemandResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $fullResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']));

        $onDemandResponse->assertOk();
        $fullResponse->assertOk();

        $this->assertNotSame($onDemandResponse->json('jobId'), $fullResponse->json('jobId'));
        $this->assertNotSame($onDemandResponse->json('track.trackId'), $fullResponse->json('track.trackId'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
    }

    public function test_romanized_and_non_romanized_tracks_are_cached_separately(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'ara',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'مرحبا بكم')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nمرحبا بكم\n",
        );

        $plainResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'romanmode01',
                'includeRomanization' => false,
            ]));

        $romanizedResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'youtubeVideoId' => 'romanmode01',
                'includeRomanization' => true,
            ]));

        $plainResponse->assertOk();
        $romanizedResponse->assertOk();

        $this->assertNotSame($plainResponse->json('jobId'), $romanizedResponse->json('jobId'));
        $this->assertNotSame($plainResponse->json('track.trackId'), $romanizedResponse->json('track.trackId'));
        $this->assertNull($plainResponse->json('track.cues.0.romanization'));
        $this->assertSame('romanized مرحبا بكم', $romanizedResponse->json('track.cues.0.romanization'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
    }

    public function test_create_subtitle_job_accepts_catalog_source_languages(): void
    {
        $sourceLanguages = ['auto', 'eng', 'cmn', 'jpn', 'ara', 'por', 'ita', 'swa'];

        foreach ($sourceLanguages as $index => $sourceLanguage) {
            $videoId = 'vid'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);

            $this
                ->withHeader('X-Extension-Install-Id', $this->installId(chr(97 + $index)))
                ->postJson('/v1/subtitle-jobs', $this->validPayload([
                    'youtubeVideoId' => $videoId,
                    'sourceLanguage' => $sourceLanguage,
                ]))
                ->assertOk()
                ->assertJsonPath('sourceLanguage', $sourceLanguage)
                ->assertJsonPath('track.sourceLanguage', $sourceLanguage);
        }

        $this->assertSame($sourceLanguages, $this->transcriptionService->sourceLanguages);
    }

    public function test_create_subtitle_job_accepts_catalog_target_languages(): void
    {
        foreach (['eng', 'cmn', 'jpn', 'fra', 'swa'] as $index => $targetLanguage) {
            $videoId = 'tgt'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);

            $this
                ->withHeader('X-Extension-Install-Id', $this->installId(chr(97 + $index)))
                ->postJson('/v1/subtitle-jobs', $this->validPayload([
                    'youtubeVideoId' => $videoId,
                    'targetLanguage' => $targetLanguage,
                ]))
                ->assertOk()
                ->assertJsonPath('targetLanguage', $targetLanguage)
                ->assertJsonPath('track.targetLanguage', $targetLanguage);
        }
    }

    public function test_auto_source_persists_detected_source_language(): void
    {
        $this->transcriptionService->transcript = new TimestampedTranscript(
            language: 'jpn',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.5, 2.1, 'konnichiwa')],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nkonnichiwa\n",
        );

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'auto',
                'targetLanguage' => 'eng',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('sourceLanguage', 'auto')
            ->assertJsonPath('detectedSourceLanguage', 'jpn')
            ->assertJsonPath('track.sourceLanguage', 'auto')
            ->assertJsonPath('track.detectedSourceLanguage', 'jpn');

        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'jpn',
            'target_language' => 'eng',
        ]);
        $this->assertDatabaseHas('subtitle_tracks', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'jpn',
            'target_language' => 'eng',
        ]);
    }

    public function test_duplicate_default_request_reuses_completed_on_demand_track(): void
    {
        $firstResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('jobId', $firstResponse->json('jobId'))
            ->assertJsonPath('track.trackId', $firstResponse->json('track.trackId'));

        $this->assertSame(1, $this->audioSource->calls);
        $this->assertSame(0, $this->translationAnalysis->calls);
        $this->assertSame(1, $this->translationAnalysis->tokenizationCalls);
    }

    public function test_old_tokenization_processing_versions_are_not_reused(): void
    {
        $oldJob = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'source_language' => 'auto',
            'detected_source_language' => 'spa',
            'target_language' => 'eng',
            'processing_version' => 'elevenlabs-scribe-v2-transcript-first-tokenizer-agent-v4',
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'install_id' => $this->installId(),
            'expires_at' => now()->addDays(30),
        ]);
        SubtitleTrack::factory()
            ->for($oldJob, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'source_language' => 'auto',
                'detected_source_language' => 'spa',
                'target_language' => 'eng',
                'processing_version' => 'elevenlabs-scribe-v2-transcript-first-tokenizer-agent-v4',
                'expires_at' => now()->addDays(30),
            ]);

        $response = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $response->assertOk();

        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
        $this->assertSame(1, $this->audioSource->calls);
        $this->assertNotSame($oldJob->public_id, $response->json('jobId'));
    }

    public function test_completed_tracks_are_cached_per_install(): void
    {
        $firstResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $secondResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId('b'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload());

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertNotSame($firstResponse->json('jobId'), $secondResponse->json('jobId'));
        $this->assertNotSame($firstResponse->json('track.trackId'), $secondResponse->json('track.trackId'));
        $this->assertSame(2, SubtitleJob::count());
        $this->assertSame(2, SubtitleTrack::count());
        $this->assertSame(2, $this->audioSource->calls);
    }

    public function test_list_subtitle_jobs_returns_current_install_history(): void
    {
        $installId = $this->installId();
        $job = SubtitleJob::factory()->create([
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'install_id' => $installId,
            'expires_at' => now()->addDays(30),
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'source_language' => 'spa',
            'detected_source_language' => 'spa',
            'updated_at' => now(),
        ]);
        $track = SubtitleTrack::factory()
            ->for($job, 'job')
            ->create([
                'youtube_video_id' => 'dQw4w9WgXcQ',
                'source_language' => 'spa',
                'detected_source_language' => 'spa',
                'expires_at' => now()->addDays(30),
            ]);
        $runningJob = SubtitleJob::factory()->create([
            'youtube_video_id' => 'run00000001',
            'install_id' => $installId,
            'expires_at' => null,
            'stage' => 'transcribing',
            'progress_percent' => 45,
            'updated_at' => now()->subMinute(),
        ]);
        $failedJob = SubtitleJob::factory()->create([
            'youtube_video_id' => 'fail0000001',
            'install_id' => $installId,
            'expires_at' => null,
            'status' => 'failed',
            'stage' => 'enriching',
            'progress_percent' => 75,
            'error_message' => 'Subtitle enrichment is temporarily rate limited.',
            'updated_at' => now()->subMinutes(2),
        ]);
        SubtitleJob::factory()->create([
            'youtube_video_id' => 'other000001',
            'install_id' => $this->installId('b'),
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->getJson('/v1/subtitle-jobs');

        $response
            ->assertOk()
            ->assertJsonCount(3, 'jobs')
            ->assertJsonPath('jobs.0.youtubeVideoId', 'dQw4w9WgXcQ')
            ->assertJsonPath('jobs.0.status', 'completed')
            ->assertJsonPath('jobs.0.trackId', $track->public_id)
            ->assertJsonPath('jobs.0.sourceLanguage', 'spa')
            ->assertJsonPath('jobs.0.detectedSourceLanguage', 'spa')
            ->assertJsonPath('jobs.0.targetLanguage', 'eng')
            ->assertJsonPath('jobs.1.youtubeVideoId', 'run00000001')
            ->assertJsonPath('jobs.1.status', 'running')
            ->assertJsonPath('jobs.1.stage', 'transcribing')
            ->assertJsonPath('jobs.1.progressPercent', 45)
            ->assertJsonPath('jobs.1.jobId', $runningJob->public_id)
            ->assertJsonPath('jobs.1.targetLanguage', 'eng')
            ->assertJsonPath('jobs.2.youtubeVideoId', 'fail0000001')
            ->assertJsonPath('jobs.2.status', 'failed')
            ->assertJsonPath('jobs.2.stage', 'enriching')
            ->assertJsonPath('jobs.2.progressPercent', 75)
            ->assertJsonPath('jobs.2.message', 'Subtitle enrichment is temporarily rate limited.')
            ->assertJsonPath('jobs.2.jobId', $failedJob->public_id);
    }

    public function test_learning_token_enrichment_updates_track_and_skips_duplicate_provider_calls(): void
    {
        $jobResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $payload = [
            'trackId' => $jobResponse->json('track.trackId'),
            'cueId' => 'cue-0001',
            'tokenIndex' => 0,
        ];

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('trackId', $payload['trackId'])
            ->assertJsonPath('cueId', 'cue-0001')
            ->assertJsonPath('token.index', 0)
            ->assertJsonPath('token.text', 'first')
            ->assertJsonPath('token.gloss', 'first gloss')
            ->assertJsonPath('token.romanization', 'first romanized');

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/learning-tokens', $payload)
            ->assertOk()
            ->assertJsonPath('token.gloss', 'first gloss');

        $track = SubtitleTrack::where('public_id', $payload['trackId'])->firstOrFail();

        $this->assertSame('first gloss', $track->cues[0]['tokens'][0]['gloss']);
        $this->assertSame(1, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_skips_provider_for_same_language_track(): void
    {
        $jobResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload([
                'sourceLanguage' => 'eng',
                'targetLanguage' => 'eng',
            ]))
            ->assertOk();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/learning-tokens', [
                'trackId' => $jobResponse->json('track.trackId'),
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('token.text', 'first');

        $this->assertSame(0, $this->translationAnalysis->tokenCalls);
    }

    public function test_learning_token_enrichment_requires_owning_install(): void
    {
        $jobResponse = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId('b'))
            ->postJson('/v1/learning-tokens', [
                'trackId' => $jobResponse->json('track.trackId'),
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_transcription_failure_returns_stable_error_and_status(): void
    {
        $this->transcriptionService->shouldFail = true;

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'transcription_failed');

        $this->assertSame(1, SubtitleJob::count());
        $this->assertSame(0, SubtitleTrack::count());
        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'status' => 'failed',
            'stage' => 'transcribing',
            'error_code' => 'transcription_failed',
        ]);
        $this->assertFalse(File::exists($this->audioSource->lastAudioPath));
    }

    public function test_full_enrichment_failure_returns_stable_error_and_status(): void
    {
        $this->translationAnalysis->shouldFail = true;

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'enrichment_failed');

        $this->assertDatabaseHas('subtitle_jobs', [
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'status' => 'failed',
            'stage' => 'enriching',
            'error_code' => 'enrichment_failed',
        ]);
    }

    public function test_create_subtitle_job_returns_stable_validation_errors(): void
    {
        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs', [
                'youtubeVideoId' => 'dQw4w9WgXcQ',
                'sourceLanguage' => 'zz',
                'targetLanguage' => 'auto',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'requestId']);
    }

    public function test_api_requires_extension_install_id(): void
    {
        Log::spy();

        $this
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['requestId']);

        Log::shouldHaveReceived('warning')
            ->with('backend.proxy_invalid_install_id', \Mockery::on(
                fn (array $context): bool => isset($context['request_id'], $context['ip']),
            ));
    }

    public function test_no_cancel_route_is_exposed(): void
    {
        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/subtitle-jobs/'.(string) Str::uuid().'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $videoId = (string) ($overrides['youtubeVideoId'] ?? 'dQw4w9WgXcQ');

        return [
            'youtubeVideoId' => $videoId,
            'youtubeUrl' => 'https://www.youtube.com/watch?v='.$videoId,
            'videoDurationSeconds' => 213,
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completedJobShape(): array
    {
        return [
            'jobId',
            'youtubeVideoId',
            'sourceLanguage',
            'targetLanguage',
            'createdAt',
            'updatedAt',
            'expiresAt',
            'track' => [
                'trackId',
                'jobId',
                'youtubeVideoId',
                'sourceLanguage',
                'targetLanguage',
                'generatedAt',
                'expiresAt',
                'webVtt',
                'cues' => [
                    '*' => ['cueId', 'index', 'startMs', 'endMs', 'sourceText', 'translatedText', 'tokens'],
                ],
            ],
        ];
    }

    private function installId(string $character = 'a'): string
    {
        return 'install_'.str_repeat($character, 32);
    }

    private function sampleWebVtt(): string
    {
        return "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n";
    }
}

class RecordingYouTubeAudioSource extends YouTubeAudioSource
{
    public int $calls = 0;

    public ?string $lastAudioPath = null;

    public function acquire(string $videoId, ?string $youtubeUrl, ?int $requestDurationSeconds): TemporaryAudioFile
    {
        $this->calls++;

        $directory = storage_path('framework/testing/audio-api/'.(string) Str::uuid());
        File::ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$videoId.'.m4a';
        File::put($path, 'fake-audio');
        $this->lastAudioPath = $path;

        return new TemporaryAudioFile(
            path: $path,
            directory: $directory,
            durationSeconds: 42,
            sizeBytes: File::size($path),
            mimeType: 'audio/mp4',
        );
    }
}

class RecordingTranscriptionService implements TranscriptionService
{
    public bool $shouldFail = false;

    public ?TimestampedTranscript $transcript = null;

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        $this->sourceLanguages[] = $sourceLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::transcriptionFailed();
        }

        if ($this->transcript !== null) {
            return $this->transcript;
        }

        return new TimestampedTranscript(
            language: $sourceLanguage === 'auto' ? 'spa' : $sourceLanguage,
            durationSeconds: 42.0,
            segments: [
                new TimestampedTranscriptSegment(0.5, 2.1, 'first transcript segment'),
                new TimestampedTranscriptSegment(2.4, 4.0, 'second transcript segment'),
            ],
            webVtt: "WEBVTT\n\n00:00:00.500 --> 00:00:02.100\nfirst transcript segment\n\n00:00:02.400 --> 00:00:04.000\nsecond transcript segment\n",
        );
    }
}

class RecordingTranslationAnalysisProvider extends LaravelAiTranslationAnalysisProvider
{
    public function __construct() {}

    public int $calls = 0;

    public int $tokenizationCalls = 0;

    public int $romanizationCalls = 0;

    public int $tokenCalls = 0;

    public bool $shouldFail = false;

    public bool $tokenizationShouldFail = false;

    public bool $romanizationShouldFail = false;

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    /**
     * @var array<int, string>
     */
    public array $targetLanguages = [];

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function tokenize(array $cues, string $sourceLanguage): CueEnrichmentResult
    {
        $this->tokenizationCalls++;
        $this->sourceLanguages[] = $sourceLanguage;

        if ($this->tokenizationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'tokens' => $this->tokenizeCue((string) $cue['sourceText'], $sourceLanguage),
                ],
                $cues,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function enrich(
        array $cues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization = true,
    ): CueEnrichmentResult {
        $this->calls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                function (array $cue) use ($includeRomanization): array {
                    $enrichedCue = [
                        ...$cue,
                        'translatedText' => 'Translated '.$cue['sourceText'],
                        'tokens' => array_map(
                            fn (array $token): array => [
                                ...$token,
                                'gloss' => $this->glossForToken((string) $token['text']),
                                ...($includeRomanization && is_string($token['romanization'] ?? null)
                                    ? ['romanization' => $token['romanization']]
                                    : []),
                            ],
                            $cue['tokens'],
                        ),
                    ];

                    if ($includeRomanization && is_string($cue['romanization'] ?? null)) {
                        $enrichedCue['romanization'] = $cue['romanization'];
                    }

                    return $enrichedCue;
                },
                $cues,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     */
    public function romanize(array $cues, string $sourceLanguage): CueEnrichmentResult
    {
        $this->romanizationCalls++;

        if ($this->romanizationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'romanization' => $this->romanizationForCue((string) $cue['sourceText'], $sourceLanguage),
                    'tokens' => array_map(
                        fn (array $token): array => [
                            ...$token,
                            'romanization' => $this->romanizationForToken((string) $token['text']),
                        ],
                        $cue['tokens'],
                    ),
                ],
                $cues,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function enrichToken(array $cue, array $token, string $sourceLanguage, string $targetLanguage): array
    {
        $this->tokenCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $text = (string) $token['text'];

        return [
            'index' => $token['index'],
            'text' => $text,
            'normalizedText' => $token['normalizedText'] ?? strtolower($text),
            'gloss' => $text.' gloss',
            'romanization' => $text.' romanized',
            'usageNote' => 'Clicked token from cue '.($cue['cueId'] ?? 'unknown').'.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokenizeCue(string $sourceText, string $sourceLanguage): array
    {
        if ($sourceLanguage === 'jpn') {
            $comparableText = $this->comparableText($sourceText);

            if ($comparableText === '私は日本語を勉強しています') {
                return $this->japaneseLearningTokens();
            }

            if ($comparableText === 'ねえ今思っていてあそうじゃな') {
                return [
                    ['index' => 0, 'text' => 'ねえ', 'normalizedText' => 'ねえ'],
                    ['index' => 1, 'text' => '今', 'normalizedText' => '今'],
                    ['index' => 2, 'text' => '思っていて', 'normalizedText' => '思っていて'],
                    ['index' => 3, 'text' => 'あそうじゃな', 'normalizedText' => 'あそうじゃな'],
                ];
            }
        }

        $words = array_values(array_filter(
            preg_split('/\s+/u', trim($sourceText)) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            $words = [$sourceText];
        }

        return array_map(
            fn (string $word, int $index): array => [
                'index' => $index,
                'text' => $word,
                'normalizedText' => strtolower($word),
            ],
            $words,
            array_keys($words),
        );
    }

    private function glossForToken(string $token): string
    {
        return match ($token) {
            '日本語' => 'Japanese language',
            '勉強しています' => 'am studying',
            default => $token,
        };
    }

    private function romanizationForCue(string $sourceText, string $sourceLanguage): string
    {
        return $sourceLanguage === 'jpn' && $this->comparableText($sourceText) === '私は日本語を勉強しています'
            ? 'watashi wa nihongo o benkyo shite imasu'
            : 'romanized '.$sourceText;
    }

    private function romanizationForToken(string $token): string
    {
        return match ($token) {
            '私' => 'watashi',
            'は' => 'wa',
            '日本語' => 'nihongo',
            'を' => 'o',
            '勉強しています' => 'benkyo shite imasu',
            default => 'romanized '.$token,
        };
    }

    private function comparableText(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function japaneseLearningTokens(): array
    {
        return [
            ['index' => 0, 'text' => '私', 'normalizedText' => '私'],
            ['index' => 1, 'text' => 'は', 'normalizedText' => 'は'],
            ['index' => 2, 'text' => '日本語', 'normalizedText' => '日本語'],
            ['index' => 3, 'text' => 'を', 'normalizedText' => 'を'],
            ['index' => 4, 'text' => '勉強しています', 'normalizedText' => '勉強しています'],
        ];
    }
}
