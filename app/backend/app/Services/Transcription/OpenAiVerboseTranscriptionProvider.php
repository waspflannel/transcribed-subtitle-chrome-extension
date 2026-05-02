<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiVerboseTranscriptionProvider
{
    public function __construct(private readonly TimestampedTranscriptNormalizer $normalizer) {}

    public function transcribe(TemporaryAudioFile $audio, TranscriptionOptions $options): TimestampedTranscript
    {
        $apiKey = config('ai.providers.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider is not configured.', [
                'provider' => 'openai',
            ]);
        }

        $stream = fopen($audio->path, 'r');

        if ($stream === false) {
            throw SubtitleProcessingException::transcriptionFailed('Temporary audio could not be opened for transcription.');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout((int) config('subtitles.transcription.connect_timeout_seconds'))
                ->timeout((int) config('subtitles.transcription.timeout_seconds'))
                ->attach('file', $stream, basename($audio->path), ['Content-Type' => $audio->mimeType])
                ->post($this->endpoint(), array_filter([
                    'model' => (string) config('subtitles.transcription.model'),
                    'language' => $options->providerLanguage(),
                    'response_format' => 'verbose_json',
                    'timestamp_granularities[]' => 'segment',
                ], fn (mixed $value): bool => $value !== null && $value !== ''));

            if ($response->failed()) {
                throw SubtitleProcessingException::transcriptionFailed(context: [
                    'provider' => 'openai',
                    'provider_status' => $response->status(),
                ]);
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw SubtitleProcessingException::transcriptionFailed('Transcription provider returned an unexpected response.', [
                    'provider' => 'openai',
                ]);
            }

            return $this->normalizer->fromOpenAiVerboseJson($data);
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::transcriptionFailed(
                context: ['provider' => 'openai', 'exception' => $exception::class],
                previous: $exception,
            );
        } finally {
            fclose($stream);
        }
    }

    private function endpoint(): string
    {
        return rtrim((string) config('ai.providers.openai.url'), '/').'/audio/transcriptions';
    }
}
