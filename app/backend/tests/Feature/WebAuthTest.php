<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_verification_notification(): void
    {
        Notification::fake();

        $this
            ->post('/register', [
                'name' => 'Beta Learner',
                'email' => 'learner@example.com',
                'password' => 'correct12345',
                'password_confirmation' => 'correct12345',
            ])
            ->assertRedirect(route('verification.notice', absolute: false));

        $user = User::query()->where('email', 'learner@example.com')->firstOrFail();

        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);
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

    public function test_user_can_verify_email_and_open_dashboard(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(5),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this
            ->actingAs($user)
            ->get($url)
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $this
            ->actingAs($user->fresh())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee($user->email);
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
        ]);

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
    }
}
