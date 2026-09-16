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
use Illuminate\Support\Str;
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
        $this->assertNull($user->web_sessions_revoked_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $issuedToken->accessToken->id]);
    }

    public function test_independent_password_reset_rejects_old_web_sessions_including_legacy_hashless_sessions(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password1')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'old-password1'])->assertRedirect();
        $stolenSession = session()->all();
        $this->assertArrayHasKey('password_hash_web', $stolenSession);

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'new-password1',
            'password_confirmation' => 'new-password1',
        ])->assertRedirectToRoute('login');

        foreach ([true, false] as $hasPasswordHash) {
            $replayedSession = $stolenSession;
            if (! $hasPasswordHash) {
                unset($replayedSession['password_hash_web']);
            }

            foreach ([['GET', '/dashboard'], ['GET', '/dashboard/jobs/123'], ['POST', '/billing/portal'], ['DELETE', '/dashboard/jobs']] as [$method, $uri]) {
                $this->flushSession();
                $this->withSession($replayedSession);
                $this->app['auth']->forgetGuards();
                $this->call($method, $uri)->assertRedirectToRoute('login');
                $this->assertGuest();
            }
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'new-password1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->get('/dashboard')->assertOk();
    }

    public function test_registration_is_bounded_per_ip_across_different_email_addresses(): void
    {
        config(['fortify.abuse_limits.registration.ip_per_hour' => 2]);

        for ($index = 0; $index < 3; $index++) {
            $response = $this->post('/register', [
                'name' => 'Learner', 'email' => "learner{$index}@example.test",
                'password' => 'correct12345', 'password_confirmation' => 'correct12345',
            ]);
            if ($index === 2) {
                $response->assertStatus(429);
            } else {
                $response->assertRedirectToRoute('dashboard');
                $this->post('/logout')->assertRedirect();
                $this->app['auth']->forgetGuards();
            }
        }

        $this->assertSame(2, User::count());
    }

    public function test_database_session_cookie_cannot_be_replayed_after_password_reset_from_an_independent_session(): void
    {
        config(['session.driver' => 'database', 'session.connection' => 'sqlite']);
        $this->app['session']->forgetDrivers();
        $this->app->instance('session.store', $this->app['session']->driver());
        $user = User::factory()->create(['password' => Hash::make('old-password1')]);
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'old-password1'])->assertRedirect();
        $oldCookie = $login->getCookie(config('session.cookie'))->getValue();
        $oldRow = (array) DB::table('sessions')->where('id', $oldCookie)->first();
        $this->assertSame($user->id, $oldRow['user_id']);

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->setId(Str::random(40));
        $this->post('/reset-password', [
            'token' => Password::broker()->createToken($user), 'email' => $user->email,
            'password' => 'new-password1', 'password_confirmation' => 'new-password1',
        ])->assertRedirectToRoute('login');
        $this->assertDatabaseMissing('sessions', ['id' => $oldCookie]);

        foreach ([true, false] as $hashless) {
            $payload = unserialize(base64_decode($oldRow['payload']));
            if ($hashless) {
                unset($payload['password_hash_web']);
            }
            DB::table('sessions')->updateOrInsert(['id' => $oldCookie], [...$oldRow, 'payload' => base64_encode(serialize($payload))]);
            $this->app['auth']->forgetGuards();
            $this->withCookie(config('session.cookie'), $oldCookie)->get('/dashboard')->assertRedirectToRoute('login');
        }
    }

    public function test_registration_is_bounded_globally_across_different_ips(): void
    {
        config(['fortify.abuse_limits.registration.global_per_hour' => 2]);

        for ($index = 0; $index < 3; $index++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])->post('/register', [
                'name' => 'Learner', 'email' => "learner{$index}@example.test",
                'password' => 'correct12345', 'password_confirmation' => 'correct12345',
            ]);
            if ($index === 2) {
                $response->assertStatus(429);
            } else {
                $response->assertRedirectToRoute('dashboard');
                $this->post('/logout')->assertRedirect();
                $this->app['auth']->forgetGuards();
            }
        }

        $this->assertSame(2, User::count());
    }

    public function test_reset_mail_is_bounded_per_ip_across_different_addresses(): void
    {
        Notification::fake();
        config(['fortify.abuse_limits.password-reset-mail.ip_per_hour' => 2]);

        foreach (User::factory()->count(3)->create() as $index => $user) {
            $response = $this->post('/forgot-password', ['email' => $user->email]);
            $index < 2 ? $response->assertRedirect() : $response->assertStatus(429);
        }

        Notification::assertCount(2);
    }

    public function test_reset_mail_is_bounded_globally_across_different_ips(): void
    {
        Notification::fake();
        config(['fortify.abuse_limits.password-reset-mail.global_per_hour' => 2]);

        foreach (User::factory()->count(3)->create() as $index => $user) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
                ->post('/forgot-password', ['email' => $user->email]);
            $index < 2 ? $response->assertRedirect() : $response->assertStatus(429);
        }

        Notification::assertCount(2);
    }

    public function test_reset_mail_keeps_broker_throttle_and_returns_the_same_public_message_for_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $message = 'If an account matches that email, a password reset link will be sent.';

        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->assertJsonPath('message', $message);
        Notification::assertCount(1);
    }
}
