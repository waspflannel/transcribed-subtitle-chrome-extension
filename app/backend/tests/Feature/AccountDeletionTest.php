<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Audio\SubtitleAudioWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_requires_auth(): void
    {
        $this
            ->delete(route('account.destroy', absolute: false))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_dashboard_shows_the_delete_account_box(): void
    {
        $this
            ->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeText('Delete account')
            ->assertSeeText('Permanent, immediate, and irreversible.');
    }

    public function test_account_deletion_rejects_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('dashboard', absolute: false))
            ->delete(route('account.destroy', absolute: false), ['password' => 'wrong-password'])
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHasErrorsIn('deleteAccount', ['password']);

        $this->assertModelExists($user);
    }

    public function test_account_deletion_removes_the_user_jobs_tokens_and_audio_workspaces(): void
    {
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create(['status' => 'completed']);
        $user->createToken('Chrome extension test-install');
        $directory = SubtitleAudioWorkspace::directory($job->run_id);
        File::ensureDirectoryExists($directory);

        $this
            ->actingAs($user)
            ->delete(route('account.destroy', absolute: false), ['password' => 'password'])
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'Your account and all of its data were deleted.');

        $this->assertGuest();
        $this->assertModelMissing($user);
        $this->assertModelMissing($job);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
        ]);
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_account_deletion_cancels_an_active_stripe_subscription_first(): void
    {
        config(['billing.stripe.secret' => 'sk_test_secret']);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_live' => Http::response(['id' => 'sub_live', 'status' => 'canceled']),
        ]);

        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_live',
            'stripe_subscription_id' => 'sub_live',
            'billing_subscription_status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->delete(route('account.destroy', absolute: false), ['password' => 'password'])
            ->assertRedirect(route('login', absolute: false));

        $this->assertModelMissing($user);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_live';
        });
    }

    public function test_account_survives_when_stripe_cancellation_fails(): void
    {
        config(['billing.stripe.secret' => 'sk_test_secret']);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_live' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);

        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_live',
            'stripe_subscription_id' => 'sub_live',
            'billing_subscription_status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->delete(route('account.destroy', absolute: false), ['password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHas('billing_error');

        $this->assertModelExists($user);
        $this->assertAuthenticatedAs($user);
    }

    public function test_account_deletion_treats_an_already_missing_subscription_as_cancelled(): void
    {
        config(['billing.stripe.secret' => 'sk_test_secret']);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_gone' => Http::response(['error' => ['code' => 'resource_missing']], 404),
        ]);

        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_live',
            'stripe_subscription_id' => 'sub_gone',
            'billing_subscription_status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->delete(route('account.destroy', absolute: false), ['password' => 'password'])
            ->assertRedirect(route('login', absolute: false));

        $this->assertModelMissing($user);
    }

    public function test_account_deletion_expires_outstanding_payable_checkout_before_removing_the_user(): void
    {
        config(['billing.stripe.secret' => 'sk_test_secret']);
        $user = $this->userWithPendingCheckout();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($user) {
            $this->assertModelExists($user);
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_pending/expire', $request->url());

            return Http::response(['id' => 'cs_pending', 'status' => 'expired']);
        });

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'password'])->assertRedirectToRoute('login');

        $this->assertModelMissing($user);
        Http::assertSentCount(1);
    }

    public function test_account_deletion_preserves_account_and_tokens_when_checkout_expiration_fails(): void
    {
        config(['billing.stripe.secret' => 'sk_test_secret']);
        $user = $this->userWithPendingCheckout();
        $token = $user->createToken('Chrome extension deletion-test');
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions/cs_pending/expire' => Http::response([], 503)]);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'password'])
            ->assertRedirectToRoute('dashboard')->assertSessionHas('billing_error');

        $this->assertModelExists($user);
        $this->assertModelExists($token->accessToken);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('cs_pending', $user->fresh()->stripe_checkout_session_id);
    }

    public function test_account_deletion_waits_for_reconciliation_when_checkout_completion_wins_expiration(): void
    {
        config(['billing.stripe.secret' => 'sk_test_secret']);
        $user = $this->userWithPendingCheckout();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/cs_pending/expire' => Http::response([], 400),
            'https://api.stripe.com/v1/checkout/sessions/cs_pending' => Http::response(['id' => 'cs_pending', 'status' => 'complete', 'subscription' => 'sub_paid']),
        ]);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'password'])->assertSessionHas('billing_error');

        $this->assertModelExists($user);
        $this->assertAuthenticatedAs($user);
        Http::assertSentCount(2);
    }

    public function test_account_deletion_blocks_unknown_checkout_creation_outcome(): void
    {
        $user = $this->userWithPendingCheckout();
        $user->update(['stripe_checkout_session_id' => null, 'stripe_checkout_session_url' => null]);
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions*' => Http::response(['data' => [], 'has_more' => false])]);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'password'])
            ->assertSessionHas('billing_error', fn ($message): bool => str_contains($message, 'Retry after'));

        $this->assertModelExists($user);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_account_deletion_reconciles_absent_checkout_after_its_original_expiry(): void
    {
        $user = $this->userWithPendingCheckout();
        $user->update([
            'stripe_checkout_session_id' => null,
            'stripe_checkout_session_url' => null,
            'stripe_checkout_expires_at' => now()->subHour(),
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions*' => Http::response(['data' => [], 'has_more' => false])]);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'password'])->assertRedirect(route('login'));

        $this->assertModelMissing($user);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    private function userWithPendingCheckout(): User
    {
        return User::factory()->create([
            'stripe_customer_id' => 'cus_pending',
            'stripe_checkout_intent_id' => 'abd89c6d-70c7-4a86-bdf9-1dd5a89d7b66',
            'stripe_checkout_session_id' => 'cs_pending',
            'stripe_checkout_plan_code' => 'base',
            'stripe_checkout_session_url' => 'https://checkout.stripe.test/pending',
            'stripe_checkout_expires_at' => now()->addMinutes(20),
        ]);
    }
}
