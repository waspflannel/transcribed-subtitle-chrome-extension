<?php

namespace App\Services\Transcription;

use App\Exceptions\SubtitleProcessingException;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Throwable;

class OpenAiWebVttTranscriptionService
{
    public function __construct(private readonly WebVttTranscriptParser $webVttParser) {}

    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript
    {
        $provider = Lab::OpenAI;
        $apiKey = config('ai.providers.'.$provider->value.'.key');
        $model = (string) config('ai.providers.'.$provider->value.'.models.transcription.default', 'whisper-1');

        if (! is_string($apiKey) || $apiKey === '') {
            throw SubtitleProcessingException::transcriptionFailed('Transcription provider is not configured.', [
                'provider' => $provider->value,
                'adapter' => 'openai-http',
            ]);
        }

        $this->ensureModelSupportsWebVtt($provider, $model);

        try {
            $response = $this->sendTranscriptionRequest($audio, $sourceLanguage, $provider, $apiKey, $model);

            if ($response->failed()) {
                throw SubtitleProcessingException::transcriptionFailed('Transcription provider request failed.', [
                    'provider' => $provider->value,
                    'adapter' => 'openai-http',
                    'model' => $model,
                    'status' => $response->status(),
                ]);
            }

            $parsedWebVtt = $this->webVttParser->parse($response->body());

            return new TimestampedTranscript(
                language: $sourceLanguage,
                durationSeconds: $audio->durationSeconds,
                segments: $parsedWebVtt['segments'],
                webVtt: $parsedWebVtt['webVtt'],
            );
        } catch (SubtitleProcessingException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SubtitleProcessingException::transcriptionFailed(
                context: [
                    'provider' => $provider->value,
                    'adapter' => 'openai-http',
                    'model' => $model,
                    'exception' => $exception::class,
                ],
                previous: $exception,
            );
        }
    }

    private function sendTranscriptionRequest(
        TemporaryAudioFile $audio,
        string $sourceLanguage,
        Lab $provider,
        string $apiKey,
        string $model,
    ): Response {
        $payload = [
            'model' => $model,
            'response_format' => 'vtt',
        ];

        if ($sourceLanguage !== 'auto') {
            $payload['language'] = $sourceLanguage;
        }

        return Http::withToken($apiKey)
            ->timeout((int) config('subtitles.transcription.timeout_seconds'))
            ->attach(
                'file',
                File::get($audio->path),
                $this->audioFilename($audio),
                ['Content-Type' => $audio->mimeType],
            )
            ->post($this->transcriptionUrl($provider), $payload);
    }

    private function ensureModelSupportsWebVtt(Lab $provider, string $model): void
    {
        if (! str_starts_with($model, 'gpt-4o-transcribe') && ! str_starts_with($model, 'gpt-4o-mini-transcribe')) {
            return;
        }

        throw SubtitleProcessingException::transcriptionFailed('Configured transcription model does not support WebVTT output.', [
            'provider' => $provider->value,
            'adapter' => 'openai-http',
            'model' => $model,
            'response_format' => 'vtt',
        ]);
    }

    private function transcriptionUrl(Lab $provider): string
    {
        return rtrim((string) config('ai.providers.'.$provider->value.'.url', 'https://api.openai.com/v1'), '/').'/audio/transcriptions';
    }

    private function audioFilename(TemporaryAudioFile $audio): string
    {
        return match ($audio->mimeType) {
            'audio/mpeg' => 'audio.mp3',
            'audio/wav', 'audio/x-wav' => 'audio.wav',
            'audio/webm' => 'audio.webm',
            default => 'audio.m4a',
        };
    }
}
