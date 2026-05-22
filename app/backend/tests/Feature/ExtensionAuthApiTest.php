<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use App\Support\ExtensionTokenAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ExtensionAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_issue_extension_token_and_fetch_account(): void
    {
        $user = User::factory()->create([
            'email' => 'learner@example.com',
            'password' => Hash::make('correct-password1'),
        ]);

        $login = $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/extension-auth/login', [
                'email' => 'learner@example.com',
                'password' => 'correct-password1',
            ])
            ->assertOk()
            ->assertJsonPath('account.status', 'authenticated')
            ->assertJsonPath('account.email', 'learner@example.com')
            ->assertJsonPath('token.tokenType', 'Bearer')
            ->assertJsonStructure(['token' => ['plainTextToken', 'expiresAt', 'abilities']]);

        $plainTextToken = $login->json('token.plainTextToken');
        $this->assertIsString($plainTextToken);
        $this->assertNotSame('', $plainTextToken);

        $accessToken = PersonalAccessToken::findToken($plainTextToken);
        $this->assertNotNull($accessToken);
        $this->assertTrue($accessToken->tokenable->is($user));
        $this->assertSame(ExtensionTokenAbility::DEFAULT_ABILITIES, $accessToken->abilities);
        $this->assertSame(app(ExtensionTokenIssuer::class)->tokenName($this->installId()), $accessToken->name);
        $this->assertNotNull($accessToken->expires_at);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/v1/extension-auth/account')
            ->assertOk()
            ->assertJsonPath('account.id', (string) $user->id)
            ->assertJsonMissingPath('token');
    }

    public function test_extension_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'learner@example.com',
            'password' => Hash::make('correct-password1'),
        ]);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/extension-auth/login', [
                'email' => 'learner@example.com',
                'password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_unverified_users_cannot_issue_extension_tokens(): void
    {
        User::factory()->unverified()->create([
            'email' => 'learner@example.com',
            'password' => Hash::make('correct-password1'),
        ]);

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/extension-auth/login', [
                'email' => 'learner@example.com',
                'password' => 'correct-password1',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'email_not_verified');
    }

    public function test_protected_extension_api_requires_token_and_rejects_expired_tokens(): void
    {
        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->getJson('/v1/subtitle-jobs')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $user = User::factory()->create();
        $expiredToken = $user->createToken(
            'expired extension token',
            ExtensionTokenAbility::DEFAULT_ABILITIES,
            now()->subMinute(),
        );

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->withHeader('Authorization', 'Bearer '.$expiredToken->plainTextToken)
            ->getJson('/v1/subtitle-jobs')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_token_without_required_ability_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken(
            'account-only extension token',
            [ExtensionTokenAbility::ACCOUNT_READ],
            now()->addDays(30),
        );

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/v1/subtitle-jobs')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_logout_revokes_active_token(): void
    {
        $user = User::factory()->create();
        $issuedToken = app(ExtensionTokenIssuer::class)->issue($user, $this->installId());

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->withHeader('Authorization', 'Bearer '.$issuedToken->plainTextToken)
            ->postJson('/v1/extension-auth/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull(PersonalAccessToken::findToken($issuedToken->plainTextToken));
        $this->app['auth']->forgetGuards();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->withHeader('Authorization', 'Bearer '.$issuedToken->plainTextToken)
            ->getJson('/v1/subtitle-jobs')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_logout_still_revokes_token_after_user_becomes_unverified(): void
    {
        $user = User::factory()->create();
        $issuedToken = app(ExtensionTokenIssuer::class)->issue($user, $this->installId());

        $user->forceFill(['email_verified_at' => null])->save();

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->withHeader('Authorization', 'Bearer '.$issuedToken->plainTextToken)
            ->postJson('/v1/extension-auth/logout')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull(PersonalAccessToken::findToken($issuedToken->plainTextToken));
    }

    public function test_wrong_user_cannot_access_job_or_track(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $job = SubtitleJob::factory()->for($owner)->create([
            'install_id' => $this->installId(),
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'expires_at' => now()->addDays(30),
        ]);
        $track = SubtitleTrack::factory()->for($job, 'job')->create([
            'expires_at' => now()->addDays(30),
        ]);

        $this
            ->withExtensionAuth($this->installId(), $otherUser)
            ->getJson('/v1/subtitle-jobs/'.$job->public_id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this
            ->withExtensionAuth($this->installId(), $otherUser)
            ->postJson('/v1/learning-tokens', [
                'trackId' => $track->public_id,
                'cueId' => 'cue-0001',
                'tokenIndex' => 0,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_install_id_remains_required_for_abuse_controls(): void
    {
        $this
            ->postJson('/v1/extension-auth/login', [
                'email' => 'learner@example.com',
                'password' => 'correct-password1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    private function installId(string $character = 'a'): string
    {
        return 'install_'.str_repeat($character, 32);
    }
}
