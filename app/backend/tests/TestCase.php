<?php

namespace Tests;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @var array<string, string>
     */
    private array $extensionAuthTokens = [];

    protected function withExtensionAuth(string $installId, ?User $user = null): static
    {
        $this->app['auth']->forgetGuards();

        $cacheKey = $installId.':'.($user?->getKey() ?? 'default');

        if (! isset($this->extensionAuthTokens[$cacheKey])) {
            $user ??= SubtitleJob::query()
                ->where('install_id', $installId)
                ->whereNotNull('user_id')
                ->latest('id')
                ->first()
                ?->user
                ?? User::factory()->create();
            $issuedToken = app(ExtensionTokenIssuer::class)->issue($user, $installId);
            $this->extensionAuthTokens[$cacheKey] = $issuedToken->plainTextToken;
        }

        return $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->withHeader('Authorization', 'Bearer '.$this->extensionAuthTokens[$cacheKey]);
    }
}
