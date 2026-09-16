<?php

namespace App\Http\Requests;

use Closure;
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
            'lyrics' => [
                'bail', 'required', 'string', 'max:25000',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $value)) {
                        $fail('Lyrics contain unsupported characters. Paste plain text.');
                    } elseif (! preg_match('/\p{L}/u', $value)) {
                        $fail('Paste lyrics containing words, not just numbers, punctuation, or emoji.');
                    } elseif (preg_match('/\A[\s\x{FEFF}]*(?:https?:\/\/|www\.)\S+[\s\x{FEFF}]*\z/iu', $value)) {
                        $fail('Paste the lyrics themselves, not a link.');
                    }
                },
            ],
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
