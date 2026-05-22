<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExtensionLoginRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:1', 'max:1024'],
        ];
    }

    public function email(): string
    {
        return strtolower((string) $this->validated('email'));
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }

    public function extensionInstallId(): string
    {
        return (string) $this->header('X-Extension-Install-Id');
    }
}
