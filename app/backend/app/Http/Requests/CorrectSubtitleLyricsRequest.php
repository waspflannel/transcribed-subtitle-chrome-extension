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
            'lyrics' => ['required', 'string', 'max:25000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lyrics = $this->input('lyrics');

                if (is_string($lyrics) && preg_match('/[\p{L}\p{N}]/u', $lyrics) !== 1) {
                    $validator->errors()->add('lyrics', 'Lyrics must contain at least one letter or number.');
                }
            },
        ];
    }

    public function lyrics(): string
    {
        return (string) $this->validated('lyrics');
    }
}
