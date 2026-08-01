<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_open_dashboard_immediately(): void
    {
        $this
            ->post('/register', [
                'name' => 'Beta Learner',
                'email' => 'learner@example.com',
                'password' => 'correct12345',
                'password_confirmation' => 'correct12345',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'learner@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);

        $this
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($user->email);
    }

    public function test_registration_normalizes_email_before_uniqueness_validation(): void
    {
        User::factory()->create(['email' => 'learner@example.com']);

        $this
            ->post('/register', [
                'name' => 'Beta Learner',
                'email' => 'Learner@Example.com',
                'password' => 'correct12345',
                'password_confirmation' => 'correct12345',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create([
            'email' => 'learner@example.com',
            'password' => Hash::make('correct12345'),
        ]);

        $this
            ->post('/login', [
                'email' => 'learner@example.com',
                'password' => 'correct12345',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        $this
            ->post('/logout')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }

    public function test_user_can_request_and_complete_password_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'learner@example.com',
            'password' => Hash::make('old-password1'),
            'remember_token' => 'remember-before-reset',
        ]);
        $installId = 'install_'.str_repeat('a', 32);
        $issuedToken = app(ExtensionTokenIssuer::class)->issue($user, $installId);

        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->withHeader('Authorization', 'Bearer '.$issuedToken->plainTextToken)
            ->getJson('/v1/extension-auth/account')
            ->assertOk();

        $this
            ->post('/forgot-password', ['email' => 'learner@example.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);

        $this
            ->post('/reset-password', [
                'token' => $token,
                'email' => 'learner@example.com',
                'password' => 'new-password1',
                'password_confirmation' => 'new-password1',
            ])
            ->assertRedirect(route('login', absolute: false));

        $this->assertTrue(Hash::check('new-password1', $user->fresh()->password));
        $this->assertNotSame('remember-before-reset', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $issuedToken->accessToken->id]);

        $this->app['auth']->forgetGuards();
        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->withHeader('Authorization', 'Bearer '.$issuedToken->plainTextToken)
            ->getJson('/v1/extension-auth/account')
            ->assertUnauthorized();

        $this->flushHeaders();
        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->postJson('/v1/extension-auth/login', [
                'email' => 'learner@example.com',
                'password' => 'new-password1',
            ])
            ->assertOk()
            ->assertJsonPath('account.email', 'learner@example.com');
    }

    public function test_password_and_remember_token_roll_back_if_access_token_revocation_fails(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password1'),
            'remember_token' => 'remember-before-reset',
        ]);
        $issuedToken = app(ExtensionTokenIssuer::class)->issue($user, 'install_'.str_repeat('b', 32));
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_personal_access_token_delete
            BEFORE DELETE ON personal_access_tokens
            BEGIN
                SELECT RAISE(ABORT, 'simulated token revocation failure');
            END
        SQL);

        try {
            app(ResetUserPassword::class)->reset($user, [
                'password' => 'new-password1',
                'password_confirmation' => 'new-password1',
            ]);
            $this->fail('Expected token revocation failure.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::statement('DROP TRIGGER fail_personal_access_token_delete');
        }

        $user = $user->fresh();
        $this->assertTrue(Hash::check('old-password1', $user->password));
        $this->assertSame('remember-before-reset', $user->remember_token);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $issuedToken->accessToken->id]);
    }
}
