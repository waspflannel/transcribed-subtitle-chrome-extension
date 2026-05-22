<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\ExtensionTokenAbility;
use Laravel\Sanctum\NewAccessToken;

class ExtensionTokenIssuer
{
    public function issue(User $user, string $installId): NewAccessToken
    {
        $tokenName = $this->tokenName($installId);

        $user->tokens()
            ->where('name', $tokenName)
            ->delete();

        return $user->createToken(
            $tokenName,
            ExtensionTokenAbility::DEFAULT_ABILITIES,
            now()->addDays(30),
        );
    }

    public function tokenName(string $installId): string
    {
        return 'Chrome extension '.substr(hash('sha256', $installId), 0, 16);
    }
}
