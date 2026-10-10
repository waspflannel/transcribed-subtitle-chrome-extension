<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInstanceSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
        $rules = [
            'providers' => ['sometimes', 'array:openai,cerebras,elevenlabs,claude'],
            'retentionDays' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:36500'],
        ];
        foreach (['openai', 'cerebras', 'elevenlabs', 'claude'] as $provider) {
            $rules['providers.'.$provider] = ['sometimes', 'array:apiKey'];
            $rules['providers.'.$provider.'.apiKey'] = ['sometimes', 'nullable', 'string', 'max:4096', 'regex:/^[^\x00-\x1F\x7F]*$/'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        // Preserve the distinction after Laravel converts empty strings to null.
        $original = json_decode($this->getContent(), true);
        $providers = $this->input('providers');
        if (! is_array($providers)) {
            return;
        }
        foreach ($providers as $provider => &$fields) {
            $key = $original['providers'][$provider]['apiKey'] ?? null;
            if (! is_array($fields) || ! is_string($key)) {
                continue;
            }
            // Keys never contain whitespace; terminal line wraps add it when long tokens are copied.
            $key = preg_replace('/\s+/u', '', $key);
            if ($key === '') {
                unset($fields['apiKey']);
            } else {
                $fields['apiKey'] = $key;
            }
        }
        $this->merge(['providers' => $providers]);
    }
}
