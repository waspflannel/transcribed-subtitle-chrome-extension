<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\ElevenLabsScribeAudioPreparer;
use App\Services\Audio\ScribeAudioChunker;
use App\Services\Audio\TemporaryAudioFile;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use App\Services\Transcription\ScribeChunkPayloadMerger;
use App\Services\Transcription\ScribeTranscriptNormalizer;
use App\Services\Transcription\TimestampedTranscript;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class ElevenLabsScribeTranscriptionServiceTest extends TestCase
{
    private string $directory;

    private TemporaryAudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/elevenlabs-scribe-transcription/'.(string) Str::uuid());
        File::ensureDirectoryExists($this->directory);
        $path = $this->directory.DIRECTORY_SEPARATOR.'audio.m4a';
        File::put($path, 'fake-audio');

        $this->audio = new TemporaryAudioFile(
            path: $path,
            directory: $this->directory,
            durationSeconds: 12,
            sizeBytes: File::size($path),
            mimeType: 'audio/mp4',
        );

        config([
            'ai.providers.eleven.key' => 'test-key',
            'ai.providers.eleven.url' => 'https://api.elevenlabs.test/v1',
            'ai.providers.eleven.models.transcription.default' => 'scribe_v2',
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg-test',
            'subtitles.audio_preparation.ffmpeg_timeout_seconds' => 45,
            'subtitles.audio_preparation.voice_isolation.enabled' => false,
            'subtitles.audio_preparation.voice_isolation.timeout_seconds' => 55,
            'subtitles.audio_preparation.voice_isolation.fail_open' => true,
            'subtitles.transcription.timeout_seconds' => 30,
        ]);

        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            File::put($command[array_key_last($command)], 'prepared-flac');

            return Process::result();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_requests_scribe_word_timestamps_and_normalizes_segments(): void
    {
        $requestMatched = false;

        Http::fake(function (Request $request) use (&$requestMatched) {
            $body = $request->body();
            $requestMatched = $request->url() === 'https://api.elevenlabs.test/v1/speech-to-text'
                && $request->hasHeader('xi-api-key', 'test-key')
                && str_contains($body, 'name="file"; filename="audio.flac"')
                && str_contains($body, 'prepared-flac')
                && str_contains($body, 'name="model_id"')
                && str_contains($body, 'scribe_v2')
                && str_contains($body, 'name="timestamps_granularity"')
                && str_contains($body, 'word')
                && str_contains($body, 'name="diarize"')
                && str_contains($body, 'false')
                && str_contains($body, 'name="tag_audio_events"')
                && str_contains($body, 'name="no_verbatim"')
                && str_contains($body, 'name="language_code"')
                && str_contains($body, 'spa');

            return Http::response($this->sampleScribePayload(), 200);
        });

        $transcript = $this->transcribeWholeAudio($this->audio, 'spa');

        $this->assertSame('spa', $transcript->language);
        $this->assertSame(12.0, $transcript->durationSeconds);
        $this->assertStringStartsWith('WEBVTT', $transcript->webVtt);
        $this->assertCount(2, $transcript->segments);
        $this->assertSame('Hola mundo.', $transcript->segments[0]->text);
        $this->assertTrue($requestMatched);
    }

    public function test_it_uploads_voice_isolated_prepared_flac_when_audio_isolation_is_enabled(): void
    {
        config(['subtitles.audio_preparation.voice_isolation.enabled' => true]);
        $scribeRequestMatched = false;

        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            $outputPath = $command[array_key_last($command)];

            File::put($outputPath, str_ends_with($outputPath, '.pcm') ? 'raw-pcm' : 'isolated-flac');

            return Process::result();
        });

        Http::fake(function (Request $request) use (&$scribeRequestMatched) {
            if (str_ends_with($request->url(), '/audio-isolation')) {
                return Http::response('isolated-provider-audio', 200);
            }

            $body = $request->body();
            $scribeRequestMatched = $request->url() === 'https://api.elevenlabs.test/v1/speech-to-text'
                && str_contains($body, 'name="file"; filename="audio.flac"')
                && str_contains($body, 'isolated-flac')
                && str_contains($body, 'name="diarize"')
                && str_contains($body, 'false');

            return Http::response($this->sampleScribePayload(), 200);
        });

        $transcript = $this->transcribeWholeAudio($this->audio, 'spa');

        $this->assertSame('spa', $transcript->language);
        $this->assertTrue($scribeRequestMatched);
    }

    public function test_it_passes_catalog_language_codes_directly_to_scribe(): void
    {
        $requestMatched = false;

        Http::fake(function (Request $request) use (&$requestMatched) {
            $body = $request->body();
            $requestMatched = str_contains($body, 'name="language_code"')
                && str_contains($body, 'jpn');

            return Http::response([
                ...$this->sampleScribePayload(),
                'language_code' => 'jpn',
            ], 200);
        });

        $transcript = $this->transcribeWholeAudio($this->audio, 'jpn');

        $this->assertSame('jpn', $transcript->language);
        $this->assertTrue($requestMatched);
    }

    public function test_it_omits_language_for_auto_detection(): void
    {
        $requestMatched = false;

        Http::fake(function (Request $request) use (&$requestMatched) {
            $requestMatched = ! str_contains($request->body(), 'name="language_code"');

            return Http::response($this->sampleScribePayload());
        });

        $transcript = $this->transcribeWholeAudio($this->audio, 'auto');

        $this->assertSame('spa', $transcript->language);
        $this->assertTrue($requestMatched);
    }

    public function test_it_whitelists_the_scribe_payload_stored_for_chunk_merging(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response($this->sampleScribePayload()),
        ]);

        $payload = $this->service()->transcribeChunk($this->audio, 'spa');

        $this->assertSame(['words', 'language_code'], array_keys($payload));
        $this->assertSame(['text', 'type', 'start', 'end'], array_keys($payload['words'][0]));
        $this->assertArrayNotHasKey('speaker_id', $payload['words'][0]);
        $this->assertArrayNotHasKey('language_probability', $payload);
        $this->assertArrayNotHasKey('text', $payload);
    }

    public function test_it_rejects_malformed_scribe_payloads_before_artifact_storage(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response([
                'language_code' => 'spa',
                'words' => [['text' => 'Hola', 'type' => 'word', 'start' => 'not-a-number', 'end' => 1.0]],
            ]),
        ]);

        try {
            $this->service()->transcribeChunk($this->audio, 'spa');
            $this->fail('Expected a malformed Scribe payload to be rejected.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('invalid_word_timing', $exception->context['reason']);
            $this->assertArrayNotHasKey('payload', $exception->context);
        }
    }

    public function test_it_rejects_negative_and_reversed_word_timings(): void
    {
        foreach ([[-0.1, 0.5], [1.0, 0.5]] as [$start, $end]) {
            Http::fake([
                'api.elevenlabs.test/v1/speech-to-text' => Http::response([
                    'language_code' => 'spa',
                    'words' => [['text' => 'Hola', 'type' => 'word', 'start' => $start, 'end' => $end]],
                ]),
            ]);

            try {
                $this->service()->transcribeChunk($this->audio, 'spa');
                $this->fail("Expected timing {$start} to {$end} to be rejected.");
            } catch (SubtitleProcessingException $exception) {
                $this->assertSame('invalid_word_timing', $exception->context['reason']);
            }
        }
    }

    public function test_it_normalizes_zero_duration_tokens_as_untimed(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response([
                'language_code' => 'por',
                'words' => [
                    ['text' => 'Ei', 'type' => 'word', 'start' => 0.5, 'end' => 0.5],
                    ['text' => ' ', 'type' => 'spacing', 'start' => 0.5, 'end' => 0.5],
                    ['text' => 'mundo', 'type' => 'word', 'start' => 0.5, 'end' => 1.0],
                ],
            ]),
        ]);

        $payload = $this->service()->transcribeChunk($this->audio, 'por');

        $this->assertSame(['text' => 'Ei', 'type' => 'word'], $payload['words'][0]);
        $this->assertSame(['text' => ' ', 'type' => 'spacing'], $payload['words'][1]);
        $this->assertSame(
            ['text' => 'mundo', 'type' => 'word', 'start' => 0.5, 'end' => 1.0],
            $payload['words'][2],
        );
    }

    public function test_it_allows_words_with_both_timing_fields_absent(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response([
                'language_code' => 'spa',
                'words' => [['text' => 'Hola', 'type' => 'word']],
            ]),
        ]);

        $payload = $this->service()->transcribeChunk($this->audio, 'spa');

        $this->assertSame(['text' => 'Hola', 'type' => 'word'], $payload['words'][0]);
    }

    public function test_it_merges_chunk_payloads_dropping_overlap_duplicates(): void
    {
        // Mirrors the pipeline's chunk plan for 300s audio (120s target, 2s
        // overlap): 3 chunks of 100s nominal, each cut with symmetric overlap.
        $chunks = [
            // Chunk 0: audio [0, 102], nominal [0, 100). Hears the boundary
            // word in its trailing overlap; midpoint 99.4s keeps it here.
            [
                'payload' => [
                    'language_code' => 'es',
                    'words' => [
                        ['text' => 'Hola.', 'start' => 0.5, 'end' => 0.9, 'type' => 'word'],
                        ['text' => 'frontera.', 'start' => 99.0, 'end' => 99.8, 'type' => 'word'],
                    ],
                ],
                'audioStartSeconds' => 0.0,
                'nominalStartSeconds' => 0.0,
                'nominalEndSeconds' => 100.0,
            ],
            // Chunk 1: audio [98, 202], nominal [100, 200). Hears the same
            // boundary word at its start; it must be dropped as a duplicate.
            // Detection disagrees ('en'); the first chunk stays canonical.
            [
                'payload' => [
                    'language_code' => 'en',
                    'words' => [
                        ['text' => 'frontera.', 'start' => 1.0, 'end' => 1.8, 'type' => 'word'],
                        ['text' => 'Cien', 'start' => 2.5, 'end' => 2.9, 'type' => 'word'],
                        ['text' => 'palabras.', 'start' => 3.0, 'end' => 3.6, 'type' => 'word'],
                    ],
                ],
                'audioStartSeconds' => 98.0,
                'nominalStartSeconds' => 100.0,
                'nominalEndSeconds' => 200.0,
            ],
            // Chunk 2: audio [198, 300], nominal [200, end].
            [
                'payload' => [
                    'language_code' => 'es',
                    'words' => [
                        ['text' => 'Fin.', 'start' => 2.5, 'end' => 2.9, 'type' => 'word'],
                    ],
                ],
                'audioStartSeconds' => 198.0,
                'nominalStartSeconds' => 200.0,
                'nominalEndSeconds' => null,
            ],
        ];

        $transcript = $this->service()->transcriptFromChunkPayloads($chunks, 'auto', 300);

        $this->assertSame('spa', $transcript->language);
        $this->assertSame(
            ['Hola.', 'frontera.', 'Cien palabras.', 'Fin.'],
            array_map(fn ($segment): string => $segment->text, $transcript->segments),
        );
        // Chunk-local timestamps are offset to absolute time.
        $this->assertSame(200.5, $transcript->segments[3]->startSeconds);
        $this->assertSame(1, substr_count($transcript->webVtt, 'frontera.'));
    }

    public function test_chunker_extracts_chunks_with_symmetric_overlap(): void
    {
        config([
            'subtitles.transcription.chunking.min_audio_seconds' => 240,
            'subtitles.transcription.chunking.target_seconds' => 120,
            'subtitles.transcription.chunking.overlap_seconds' => 2.0,
            'subtitles.transcription.chunking.max_chunks' => 8,
        ]);

        $longAudio = new TemporaryAudioFile(
            path: $this->audio->path,
            directory: $this->audio->directory,
            durationSeconds: 300,
            sizeBytes: $this->audio->sizeBytes,
            mimeType: 'audio/mp4',
        );

        $chunker = new ScribeAudioChunker;
        $plan = $chunker->plan($longAudio->durationSeconds);
        $chunkFiles = $chunker->split($longAudio, $plan);

        $this->assertCount(3, $chunkFiles);

        // The middle chunk is extracted with its leading overlap.
        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;

            return is_array($command)
                && in_array('-ss', $command, true)
                && in_array('98.000', $command, true)
                && in_array('-t', $command, true)
                && in_array('104.000', $command, true);
        });
    }

    public function test_it_maps_provider_http_failures_to_stable_errors(): void
    {
        Http::fake([
            'api.elevenlabs.test/v1/speech-to-text' => Http::response('rate limited', 429),
        ]);

        try {
            $this->service()->transcribeChunk($this->audio, 'spa');
            $this->fail('Expected provider failure to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame(502, $exception->status);
            $this->assertSame('eleven', $exception->context['provider']);
            $this->assertSame('elevenlabs-http', $exception->context['adapter']);
            $this->assertSame(429, $exception->context['status']);
        }
    }

    public function test_it_requires_backend_provider_configuration(): void
    {
        config(['ai.providers.eleven.key' => null]);

        try {
            $this->service()->transcribeChunk($this->audio, 'spa');
            $this->fail('Expected missing provider configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    public function test_it_requires_configured_transcription_model(): void
    {
        config(['ai.providers.eleven.models.transcription.default' => null]);

        try {
            $this->service()->transcribeChunk($this->audio, 'spa');
            $this->fail('Expected missing model configuration to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription model is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    public function test_it_requires_configured_transcription_provider_url(): void
    {
        config(['ai.providers.eleven.url' => null]);

        try {
            $this->service()->transcribeChunk($this->audio, 'spa');
            $this->fail('Expected missing provider URL to throw a stable transcription exception.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription provider URL is not configured.', $exception->getMessage());
            $this->assertSame('eleven', $exception->context['provider']);
        }

        Http::assertNothingSent();
    }

    public function test_it_rejects_unsupported_audio_mime_types(): void
    {
        $audio = new TemporaryAudioFile(
            path: $this->audio->path,
            directory: $this->audio->directory,
            durationSeconds: $this->audio->durationSeconds,
            sizeBytes: $this->audio->sizeBytes,
            mimeType: 'application/octet-stream',
        );

        try {
            $this->service()->transcribeChunk($audio, 'spa');
            $this->fail('Expected unsupported audio MIME type to fail before provider request.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame('Transcription audio type is not supported.', $exception->getMessage());
            $this->assertSame('application/octet-stream', $exception->context['mime_type']);
        }

        Http::assertNothingSent();
    }

    /**
     * The short-audio flow the pipeline runs when no chunking applies:
     * prepare, transcribe as one whole-file chunk, merge-normalize.
     */
    private function transcribeWholeAudio(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        $service = $this->service();
        $prepared = $service->prepareAudio($audio);
        $payload = $service->transcribeChunk($prepared, $sourceLanguage);

        return $service->transcriptFromChunkPayloads(
            chunks: [[
                'payload' => $payload,
                'audioStartSeconds' => 0.0,
                'nominalStartSeconds' => 0.0,
                'nominalEndSeconds' => null,
            ]],
            sourceLanguage: $sourceLanguage,
            durationSeconds: $audio->durationSeconds,
        );
    }

    private function service(): ElevenLabsScribeTranscriptionService
    {
        return new ElevenLabsScribeTranscriptionService(
            new ScribeTranscriptNormalizer,
            new ElevenLabsScribeAudioPreparer,
            new ScribeChunkPayloadMerger,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleScribePayload(): array
    {
        return [
            'language_code' => 'es',
            'language_probability' => 0.99,
            'text' => 'Hola mundo. Otra frase.',
            'words' => [
                ['text' => 'Hola', 'start' => 0.5, 'end' => 0.9, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ['text' => ' ', 'start' => 0.9, 'end' => 1.0, 'type' => 'spacing', 'speaker_id' => 'speaker_0'],
                ['text' => 'mundo.', 'start' => 1.0, 'end' => 1.4, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ['text' => 'Otra', 'start' => 2.4, 'end' => 2.9, 'type' => 'word', 'speaker_id' => 'speaker_0'],
                ['text' => 'frase.', 'start' => 3.0, 'end' => 3.4, 'type' => 'word', 'speaker_id' => 'speaker_0'],
            ],
        ];
    }
}
