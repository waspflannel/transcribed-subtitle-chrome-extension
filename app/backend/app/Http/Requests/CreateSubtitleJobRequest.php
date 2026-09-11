<?php

namespace App\Http\Requests;

use App\Services\Languages\LanguageCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateSubtitleJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'youtubeVideoId' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'youtubeUrl' => ['required', 'string', 'url', 'max:2048'],
            'videoDurationSeconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            'sourceLanguage' => ['required', 'string', Rule::in(LanguageCatalog::sourceLanguageCodes())],
            'targetLanguage' => ['required', 'string', Rule::in(LanguageCatalog::targetLanguageCodes())],
            'aiProvider' => ['sometimes', 'string', Rule::in(['openai', 'cerebras'])],
            'enrichmentMode' => ['required', 'string', Rule::in(['on_demand', 'full'])],
            'includeRomanization' => ['required', 'boolean'],
            'includeTranslation' => ['required', 'boolean'],
            'vocabularyHints' => ['sometimes', 'array', 'list', 'max:20'],
            'vocabularyHints.*' => ['required', 'string', 'max:49', 'regex:/^[^<>\{\}\[\]\\\\\s]+(?:\s+[^<>\{\}\[\]\\\\\s]+){0,4}$/u'],
        ];
    }

    public function extensionInstallId(): string
    {
        return (string) $this->header('X-Extension-Install-Id');
    }

    /**
     * @return array{youtubeVideoId: string, youtubeUrl: string, videoDurationSeconds?: int, sourceLanguage: string, targetLanguage: string, aiProvider?: string, enrichmentMode: string, includeRomanization: bool, includeTranslation: bool}
     */
    public function subtitlePayload(): array
    {
        $validated = $this->validated();
        $payload = [
            'youtubeVideoId' => $validated['youtubeVideoId'],
            'youtubeUrl' => $validated['youtubeUrl'],
            'sourceLanguage' => $validated['sourceLanguage'],
            'targetLanguage' => $validated['targetLanguage'],
            'enrichmentMode' => $validated['enrichmentMode'],
            'includeRomanization' => $this->boolean('includeRomanization'),
            'includeTranslation' => $this->boolean('includeTranslation'),
            'vocabularyHints' => $validated['vocabularyHints'] ?? [],
        ];

        if (isset($validated['aiProvider'])) {
            $payload['aiProvider'] = $validated['aiProvider'];
        }

        if (array_key_exists('videoDurationSeconds', $validated)) {
            $payload['videoDurationSeconds'] = $validated['videoDurationSeconds'];
        }

        return $payload;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provider = $this->input('aiProvider', config('ai.default'));
                if (in_array($provider, ['openai', 'cerebras'], true)) {
                    $key = config("ai.providers.{$provider}.key");
                    $model = config("ai.providers.{$provider}.models.text.default");
                    if (! is_string($key) || trim($key) === '' || ! is_string($model) || trim($model) === '') {
                        $label = $provider === 'cerebras' ? 'Cerebras' : 'Luna';
                        $validator->errors()->add('aiProvider', "{$label} is not configured on the backend.");
                    }
                }

                $url = $this->input('youtubeUrl');

                if (! is_string($url) || $url === '') {
                    return;
                }

                if (! $this->youtubeUrlMatchesVideoId($url, (string) $this->input('youtubeVideoId'))) {
                    $validator->errors()->add('youtubeUrl', 'The YouTube URL must be a supported YouTube URL for the requested video ID.');
                }
            },
        ];
    }

    private function youtubeUrlMatchesVideoId(string $url, string $videoId): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($host === 'youtu.be') {
            return trim($path, '/') === $videoId;
        }

        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            return false;
        }

        if ($path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);

            return ($query['v'] ?? null) === $videoId;
        }

        return trim($path, '/') === 'shorts/'.$videoId;
    }
}
