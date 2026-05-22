<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use LogicException;

trait ResolvesExtensionUser
{
    private function extensionUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('Extension API request is missing an authenticated user.');
        }

        return $user;
    }
}
