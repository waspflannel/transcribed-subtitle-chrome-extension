<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LookupSubtitleTrackRequest extends FormRequest
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
            'sourceLanguage' => ['required', 'string', Rule::in(['auto', 'ar'])],
            'targetLanguage' => ['required', 'string', Rule::in(['en'])],
        ];
    }
}
