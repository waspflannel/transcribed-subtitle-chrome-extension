<?php

namespace App\Services\Audio;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Enums\Lab;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class ElevenLabsScribeAudioPreparer
{
    private const ADAPTER = 'elevenlabs-audio-preparer';

    public function prepare(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $voiceIsolationEnabled = $this->voiceIsolationEnabled();

        Log::info('backend.audio_preparation_started', [
            'input_mime_type' => $audio->mimeType,
            'source_duration_seconds' => $audio->durationSeconds,
            'source_audio_bytes' => $audio->sizeBytes,
            'voice_isolation_enabled' => $voiceIsolationEnabled,
        ]);

        if (! $voiceIsolationEnabled) {
            $preparedAudio = $this->normalizeSourceToWav($audio);
            $this->logPreparedAudioReady($preparedAudio, false, false);

            return $preparedAudio;
        }

        try {
            $preparedAudio = $this->prepareWithVoiceIsolation($audio);
            $this->logPreparedAudioReady($preparedAudio, true, false);

            return $preparedAudio;
        } catch (SubtitleProcessingException $exception) {
            if (! $this->voiceIsolationFailOpen()) {
                throw $exception;
            }

            $this->logVoiceIsolationFallback($exception);

            $preparedAudio = $this->normalizeSourceToWav($audio);
            $this->logPreparedAudioReady($preparedAudio, false, true);

            return $preparedAudio;
        }
    }

    private function prepareWithVoiceIsolation(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $rawPcmPath = $audio->directory.DIRECTORY_SEPARATOR.'isolation-input.pcm';
        $isolatedOutputPath = $audio->directory.DIRECTORY_SEPARATOR.'isolated-output.bin';
        $preparedWavPath = $audio->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav';

        $this->convertSourceToRawPcm($audio->path, $rawPcmPath, $audio->directory);
        $this->isolateSpeech($rawPcmPath, $isolatedOutputPath);
        $this->convertIsolatedOutputToWav($isolatedOutputPath, $preparedWavPath, $audio->directory);

        return $this->preparedAudioFile($preparedWavPath, $audio);
    }

    private function normalizeSourceToWav(TemporaryAudioFile $audio): TemporaryAudioFile
    {
        $preparedWavPath = $audio->directory.DIRECTORY_SEPARATOR.'scribe-ready.wav';

        $this->runFfmpeg([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-nostdin',
            '-y',
            '-i',
            $audio->path,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $preparedWavPath,
        ], 'source_to_wav', $audio->directory);

        return $this->preparedAudioFile($preparedWavPath, $audio);
    }

    private function convertSourceToRawPcm(string $sourcePath, string $rawPcmPath, string $workDirectory): void
    {
        $this->runFfmpeg([
            $this->ffmpegBinary(),
            '-hide_banner',
            '-nostdin',
            '-y',
            '-i',
            $sourcePath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            '-f',
            's16le',
            $rawPcmPath,
        ], 'audio_isolation_source_pcm', $workDirectory);
    }

    private function isolateSpeech(string $rawPcmPath, string $isolatedOutputPath): void
    {
        $provider = Lab::ElevenLabs;
        $apiKey = config('ai.providers.'.$provider->value.'.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw $this->failure('Audio isolation provider is not configured.', [
                'stage' => 'audio_isolation',
                'provider' => $provider->value,
                'reason' => 'provider_not_configured',
            ]);
        }

        $stream = $this->openReadStream($rawPcmPath, 'audio_isolation');
        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders(['xi-api-key' => trim($apiKey)])
                ->timeout($this->voiceIsolationTimeoutSeconds())
                ->attach(
                    'audio',
                    $stream,
                    'isolation-input.pcm',
                    ['Content-Type' => 'application/octet-stream'],
                )
                ->post($this->audioIsolationUrl($provider), [
                    'file_format' => 'pcm_s16le_16',
                ]);
        } catch (ConnectionException $exception) {
            throw $this->failure('Audio isolation provider request failed.', [
                'stage' => 'audio_isolation',
                'provider' => $provider->value,
                'reason' => 'connection_failure',
                'exception' => $exception::class,
            ], $exception);
        } catch (Throwable $exception) {
            throw $this->failure('Audio isolation provider request failed.', [
                'stage' => 'audio_isolation',
                'provider' => $provider->value,
                'reason' => $this->isTimeoutException($exception) ? 'timeout' : 'request_exception',
                'exception' => $exception::class,
            ], $exception);
        } finally {
            fclose($stream);
        }

        Log::info('backend.audio_isolation_request_completed', [
            'provider' => $provider->value,
            'adapter' => self::ADAPTER,
            'status' => $response->status(),
            'elapsed_ms' => $this->elapsedMs($startedAt),
        ]);

        if ($response->failed()) {
            throw $this->failure('Audio isolation provider request failed.', [
                'stage' => 'audio_isolation',
                'provider' => $provider->value,
                'reason' => 'http_failure',
                'status' => $response->status(),
            ]);
        }

        $this->storeIsolationResponse($response, $isolatedOutputPath, $provider);
    }

    private function convertIsolatedOutputToWav(string $isolatedOutputPath, string $preparedWavPath, string $workDirectory): void
    {
        try {
            $this->runFfmpeg([
                $this->ffmpegBinary(),
                '-hide_banner',
                '-nostdin',
                '-y',
                '-i',
                $isolatedOutputPath,
                '-vn',
                '-ac',
                '1',
                '-ar',
                '16000',
                '-c:a',
                'pcm_s16le',
                $preparedWavPath,
            ], 'isolated_output_to_wav', $workDirectory);

            return;
        } catch (SubtitleProcessingException $containerDecodeFailure) {
            try {
                $this->runFfmpeg([
                    $this->ffmpegBinary(),
                    '-hide_banner',
                    '-nostdin',
                    '-y',
                    '-f',
                    's16le',
                    '-ar',
                    '16000',
                    '-ac',
                    '1',
                    '-i',
                    $isolatedOutputPath,
                    '-c:a',
                    'pcm_s16le',
                    $preparedWavPath,
                ], 'isolated_raw_pcm_to_wav', $workDirectory);
            } catch (SubtitleProcessingException $rawDecodeFailure) {
                $reason = ($rawDecodeFailure->context['reason'] ?? null) === 'ffmpeg_missing'
                    ? 'ffmpeg_missing'
                    : 'decode_failure';

                throw $this->failure('Audio isolation output could not be decoded.', [
                    'stage' => 'audio_isolation_decode',
                    'provider' => Lab::ElevenLabs->value,
                    'reason' => $reason,
                    'container_decode_reason' => $containerDecodeFailure->context['reason'] ?? 'ffmpeg_failure',
                    'raw_decode_reason' => $rawDecodeFailure->context['reason'] ?? 'ffmpeg_failure',
                ], $rawDecodeFailure);
            }
        }
    }

    private function storeIsolationResponse(Response $response, string $isolatedOutputPath, Lab $provider): void
    {
        $body = $response->body();

        if ($body === '' || trim($body) === '{}') {
            throw $this->failure('Audio isolation provider returned empty output.', [
                'stage' => 'audio_isolation',
                'provider' => $provider->value,
                'reason' => 'empty_output',
                'status' => $response->status(),
            ]);
        }

        File::put($isolatedOutputPath, $body);

        $this->assertUsableFile($isolatedOutputPath, 'audio_isolation', 'empty_output');
    }

    private function runFfmpeg(array $command, string $stage, string $workDirectory): void
    {
        $startedAt = microtime(true);

        try {
            $result = Process::timeout($this->ffmpegTimeoutSeconds())
                ->env($this->processEnvironment($workDirectory))
                ->run($command);
        } catch (Throwable $exception) {
            throw $this->failure('Audio preparation command could not run.', [
                'stage' => $stage,
                'reason' => $this->isTimeoutException($exception) ? 'timeout' : 'process_exception',
                'exception' => $exception::class,
            ], $exception);
        }

        if ($result->failed()) {
            $this->throwFfmpegFailure($result, $stage);
        }

        Log::info('backend.audio_preparation_ffmpeg_completed', [
            'stage' => $stage,
            'elapsed_ms' => $this->elapsedMs($startedAt),
        ]);
    }

    private function throwFfmpegFailure(ProcessResult $result, string $stage): never
    {
        $reason = $this->isMissingFfmpegFailure($result) ? 'ffmpeg_missing' : 'ffmpeg_failure';

        throw $this->failure('Audio preparation command failed.', [
            'stage' => $stage,
            'reason' => $reason,
            'exit_code' => $result->exitCode(),
        ]);
    }

    private function preparedAudioFile(string $preparedWavPath, TemporaryAudioFile $sourceAudio): TemporaryAudioFile
    {
        $this->assertUsableFile($preparedWavPath, 'source_to_wav', 'empty_output');

        return new TemporaryAudioFile(
            path: $preparedWavPath,
            directory: $sourceAudio->directory,
            durationSeconds: $sourceAudio->durationSeconds,
            sizeBytes: File::size($preparedWavPath),
            mimeType: 'audio/wav',
        );
    }

    private function assertUsableFile(string $path, string $stage, string $reason): void
    {
        if (! File::isFile($path) || File::size($path) < 1) {
            throw $this->failure('Audio preparation produced an empty file.', [
                'stage' => $stage,
                'provider' => Lab::ElevenLabs->value,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return resource
     */
    private function openReadStream(string $path, string $stage)
    {
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw $this->failure('Audio preparation file could not be opened.', [
                'stage' => $stage,
                'provider' => Lab::ElevenLabs->value,
                'reason' => 'file_open_failed',
            ]);
        }

        return $stream;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failure(string $message, array $context, ?Throwable $previous = null): SubtitleProcessingException
    {
        return SubtitleProcessingException::transcriptionFailed($message, [
            'adapter' => self::ADAPTER,
            ...$context,
        ], $previous);
    }

    private function logVoiceIsolationFallback(SubtitleProcessingException $exception): void
    {
        Log::warning('backend.audio_preparation_fallback_used', [
            'stage' => 'audio_isolation',
            'provider' => Lab::ElevenLabs->value,
            'adapter' => self::ADAPTER,
            'reason' => $this->safeContextString($exception->context['reason'] ?? null, 'unknown'),
            ...$this->optionalSafeScalar('status', $exception->context['status'] ?? null),
            ...$this->optionalSafeScalar('failure_stage', $exception->context['stage'] ?? null),
        ]);
    }

    private function logPreparedAudioReady(
        TemporaryAudioFile $preparedAudio,
        bool $voiceIsolationUsed,
        bool $voiceIsolationFallbackUsed,
    ): void {
        Log::info('backend.audio_preparation_completed', [
            'prepared_audio_bytes' => $preparedAudio->sizeBytes,
            'prepared_mime_type' => $preparedAudio->mimeType,
            'voice_isolation_enabled' => $this->voiceIsolationEnabled(),
            'voice_isolation_used' => $voiceIsolationUsed,
            'voice_isolation_fallback_used' => $voiceIsolationFallbackUsed,
        ]);
    }

    private function audioIsolationUrl(Lab $provider): string
    {
        $url = config('ai.providers.'.$provider->value.'.url');

        if (! is_string($url) || trim($url) === '') {
            throw $this->failure('Audio isolation provider URL is not configured.', [
                'stage' => 'audio_isolation',
                'provider' => $provider->value,
                'reason' => 'provider_url_not_configured',
            ]);
        }

        return rtrim(trim($url), '/').'/audio-isolation';
    }

    private function ffmpegBinary(): string
    {
        $binary = config('subtitles.audio_preparation.ffmpeg_binary', 'ffmpeg');

        if (! is_string($binary) || trim($binary) === '') {
            return 'ffmpeg';
        }

        return trim($binary);
    }

    private function ffmpegTimeoutSeconds(): int
    {
        return max(1, (int) config('subtitles.audio_preparation.ffmpeg_timeout_seconds', 600));
    }

    private function voiceIsolationEnabled(): bool
    {
        return (bool) config('subtitles.audio_preparation.voice_isolation.enabled', false);
    }

    private function voiceIsolationFailOpen(): bool
    {
        return (bool) config('subtitles.audio_preparation.voice_isolation.fail_open', true);
    }

    private function voiceIsolationTimeoutSeconds(): int
    {
        return max(1, (int) config('subtitles.audio_preparation.voice_isolation.timeout_seconds', 600));
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(string $workDirectory): array
    {
        $tempDirectory = $workDirectory.DIRECTORY_SEPARATOR.'process-temp';

        File::ensureDirectoryExists($tempDirectory, 0700);

        $environment = [];

        foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'Path', 'PATH', 'PATHEXT', 'USERPROFILE', 'LOCALAPPDATA', 'APPDATA', 'PROGRAMDATA'] as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return [
            ...$environment,
            'TEMP' => $tempDirectory,
            'TMP' => $tempDirectory,
            'TMPDIR' => $tempDirectory,
        ];
    }

    private function isMissingFfmpegFailure(ProcessResult $result): bool
    {
        $errorOutput = strtolower($result->errorOutput());

        return str_contains($errorOutput, 'not recognized as an internal or external command')
            || str_contains($errorOutput, 'command not found')
            || str_contains($errorOutput, 'no such file or directory')
            || str_contains($errorOutput, 'the system cannot find the file specified');
    }

    private function isTimeoutException(Throwable $exception): bool
    {
        return $exception instanceof ProcessTimedOutException
            || str_contains($exception::class, 'Timeout')
            || str_contains($exception::class, 'TimedOut');
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @return array<string, int|float|bool|string>
     */
    private function optionalSafeScalar(string $key, mixed $value): array
    {
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return [$key => $value];
        }

        if (is_string($value) && trim($value) !== '') {
            return [$key => $this->safeContextString($value, 'unknown')];
        }

        return [];
    }

    private function safeContextString(mixed $value, string $default): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return mb_substr(trim($value), 0, 120);
    }
}
