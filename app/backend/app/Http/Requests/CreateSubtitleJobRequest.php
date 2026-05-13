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
            'youtubeUrl' => ['sometimes', 'string', 'url', 'max:2048'],
            'videoDurationSeconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            'sourceLanguage' => ['required', 'string', Rule::in(LanguageCatalog::sourceLanguageCodes())],
            'targetLanguage' => ['required', 'string', Rule::in(LanguageCatalog::targetLanguageCodes())],
            'enrichmentMode' => ['sometimes', 'string', Rule::in(['on_demand', 'full'])],
            'includeRomanization' => ['sometimes', 'boolean'],
        ];
    }

    public function extensionInstallId(): string
    {
        return (string) $this->header('X-Extension-Install-Id');
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $url = $this->input('youtubeUrl');

                if (! is_string($url) || $url === '') {
                    return;
                }

                if (! $this->youtubeUrlMatchesVideoId($url, (string) $this->input('youtubeVideoId'))) {
                    $validator->errors()->add('youtubeUrl', 'The YouTube URL must be a supported watch URL for the requested video ID.');
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

        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) || $path !== '/watch') {
            return false;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        return ($query['v'] ?? null) === $videoId;
    }
}
