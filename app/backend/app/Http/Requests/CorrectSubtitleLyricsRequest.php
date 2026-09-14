<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CorrectSubtitleLyricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expectedTrackId' => ['required', 'uuid'],
            'lyrics' => ['required', 'string', 'max:25000'],
            'allowPartial' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->exists('allowPartial') && ! is_bool($this->input('allowPartial'))) {
                    $validator->errors()->add('allowPartial', 'The allowPartial field must be a boolean.');
                }
            },
        ];
    }

    public function lyrics(): string
    {
        return (string) $this->validated('lyrics');
    }
}
