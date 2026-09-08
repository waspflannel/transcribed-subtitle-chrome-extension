<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QuickFixSubtitleTokenRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expectedTrackId' => ['required', 'uuid'],
            'text' => ['required', 'string', 'max:84', 'not_regex:/^\s*$/u'],
        ];
    }
}
