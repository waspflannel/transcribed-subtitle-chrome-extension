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
}
