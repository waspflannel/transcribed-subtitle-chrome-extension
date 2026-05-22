<?php

namespace App\Actions\Fortify;

use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationRule;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'];
    }
}
