<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'sourceLanguage' => ['required', 'string', Rule::in(['auto', 'ar'])],
            'targetLanguage' => ['required', 'string', Rule::in(['en'])],
            'options' => ['required', 'array:includeRomanization,includeGloss'],
            'options.includeRomanization' => ['required', 'boolean'],
            'options.includeGloss' => ['required', 'boolean'],
        ];
    }

    public function extensionInstallId(): string
    {
        return (string) $this->header('X-Extension-Install-Id');
    }
}
