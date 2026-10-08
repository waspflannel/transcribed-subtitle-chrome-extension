<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnrichLearningTokenRequest extends FormRequest
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
            'trackId' => ['required', 'string', 'uuid'],
            'cueId' => ['required', 'string', 'min:1', 'max:64'],
            'tokenIndex' => ['required', 'integer', 'min:0'],
        ];
    }
}
