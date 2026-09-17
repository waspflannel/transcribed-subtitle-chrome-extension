<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StripeCheckoutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_retry_waits_for_original_expiry_then_replaces_an_absent_checkout(): void
    {
        $this->freezeTime();
        config(['billing.plans.base.stripe_price_id' => 'price_base']);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_recovery']);
        $creates = [];
        Http::fake(function ($request) use (&$creates) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => [], 'has_more' => false]);
            }

            $creates[] = ['key' => $request->header('Idempotency-Key'), 'expires_at' => $request['expires_at']];
            if (count($creates) === 1) {
                throw new ConnectionException('Request did not reach Stripe.');
            }

            $this->assertGreaterThanOrEqual(now()->addMinutes(30)->timestamp, $request['expires_at']);

            return Http::response(['id' => 'cs_replacement', 'url' => 'https://checkout.stripe.test/replacement']);
        });

        $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'base']))->assertSessionHas('billing_error');
        $originalIntent = $user->fresh()->stripe_checkout_intent_id;
        $originalExpiry = $user->fresh()->stripe_checkout_expires_at;

        $this->travel(2)->minutes();
        $this->actingAs($user->fresh())->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertSessionHas('billing_error', fn ($message): bool => str_contains($message, 'Retry after '.$originalExpiry->format('Y-m-d H:i:s').' UTC'));
        $this->assertCount(1, $creates);
        $this->assertSame($originalIntent, $user->fresh()->stripe_checkout_intent_id);

        // Stripe may prune idempotency keys after 24 hours. An old create is never replayed.
        $this->travel(2)->days();
        $this->actingAs($user->fresh())->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/replacement');

        $this->assertCount(2, $creates);
        $this->assertNotSame($originalIntent, $user->fresh()->stripe_checkout_intent_id);
        $this->assertNotSame($creates[0]['key'], $creates[1]['key']);
        $this->assertGreaterThan($creates[0]['expires_at'], $creates[1]['expires_at']);
        Http::assertSentCount(3);
    }

    public function test_delayed_retry_recovers_an_open_session_from_a_later_page_without_creating_again(): void
    {
        $user = $this->unknownCheckout();
        $expiresAt = $user->stripe_checkout_expires_at->timestamp;
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('cus_recovery', $request['customer']);
            $this->assertEquals(100, $request['limit']);

            return Http::response(isset($request['starting_after'])
                ? ['data' => [$this->checkoutSession('open')], 'has_more' => false]
                : ['data' => [['id' => 'cs_unrelated', 'customer' => 'cus_recovery', 'metadata' => []]], 'has_more' => true]);
        });

        $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/recovered');

        $this->assertSame('cs_recovered', $user->fresh()->stripe_checkout_session_id);
        $this->assertSame('intent_recovery', $user->fresh()->stripe_checkout_intent_id);
        $this->assertSame($expiresAt, $user->fresh()->stripe_checkout_expires_at->timestamp);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => ($request['starting_after'] ?? null) === 'cs_unrelated');
    }

    public function test_confirmed_expired_session_can_be_replaced_before_original_local_expiry(): void
    {
        $user = $this->unknownCheckout();
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => [$this->checkoutSession('expired')], 'has_more' => false]);
            }

            $this->assertNotSame('intent_recovery', $request['metadata']['checkout_intent_id']);

            return Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.test/new']);
        });

        $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/new');

        $this->assertNotSame('intent_recovery', $user->fresh()->stripe_checkout_intent_id);
        Http::assertSentCount(2);
    }

    public function test_completed_session_blocks_replacement_and_account_deletion_after_local_expiry(): void
    {
        $user = $this->unknownCheckout();
        $user->update(['stripe_checkout_expires_at' => now()->subDays(2)]);
        $user->createToken('existing-device');
        Http::fake(['https://api.stripe.com/v1/checkout/sessions*' => Http::response([
            'data' => [$this->checkoutSession('complete')], 'has_more' => false,
        ])]);

        $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertSessionHas('billing_error', fn ($message): bool => str_contains($message, 'previous checkout has completed'));
        $this->actingAs($user->fresh())->delete(route('account.destroy'), ['password' => 'password'])
            ->assertSessionHas('billing_error', fn ($message): bool => str_contains($message, 'previous checkout has completed'));

        $this->assertModelExists($user);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('intent_recovery', $user->fresh()->stripe_checkout_intent_id);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_switching_plans_expires_a_recovered_open_session_before_creating_another(): void
    {
        $user = $this->unknownCheckout();
        config(['billing.plans.plus.stripe_price_id' => 'price_plus']);
        $operations = [];
        Http::fake(function ($request) use (&$operations) {
            $operations[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET') {
                return Http::response(['data' => [$this->checkoutSession('open')], 'has_more' => false]);
            }
            if (str_ends_with($request->url(), '/expire')) {
                return Http::response($this->checkoutSession('expired'));
            }

            return Http::response(['id' => 'cs_plus', 'url' => 'https://checkout.stripe.test/plus']);
        });

        $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'plus']))
            ->assertRedirect('https://checkout.stripe.test/plus');

        $this->assertSame([
            'GET /v1/checkout/sessions',
            'POST /v1/checkout/sessions/cs_recovered/expire',
            'POST /v1/checkout/sessions',
        ], $operations);
        $this->assertSame('plus', $user->fresh()->stripe_checkout_plan_code);
    }

    #[DataProvider('interfaceLocales')]
    public function test_pending_checkout_messages_are_localized_before_flashing(string $locale): void
    {
        $this->freezeTime();
        $lookupStatus = 'absent';
        Http::fake(function ($request) use (&$lookupStatus) {
            $this->assertSame('GET', $request->method());

            return Http::response([
                'data' => $lookupStatus === 'complete' ? [$this->checkoutSession('complete')] : [],
                'has_more' => false,
            ]);
        });
        $messages = json_decode(file_get_contents(base_path('../../packages/localization/website/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $user = $this->unknownCheckout();

        foreach (['absent', 'complete'] as $lookupStatus) {
            $expected = $lookupStatus === 'complete'
                ? $messages['Your previous checkout has completed. Wait for your billing status to update, then try again. Contact support if it does not update.']
                : str_replace(':retryAt', $user->stripe_checkout_expires_at->utc()->format('Y-m-d H:i:s').' UTC',
                    $messages['Your previous checkout could not be confirmed. Retry after :retryAt to start checkout or delete your account.']);

            $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'base', 'lang' => $locale]))
                ->assertRedirect(route('dashboard'))->assertSessionHas('billing_error', $expected);
            $this->get('/dashboard?lang='.$locale)->assertOk()->assertSeeText($expected)->assertDontSee(':retryAt');
            $this->delete(route('account.destroy', ['lang' => $locale]), ['password' => 'password'])
                ->assertRedirect(route('dashboard'))->assertSessionHas('billing_error', $expected);
            $this->assertModelExists($user);
            $this->assertSame('intent_recovery', $user->fresh()->stripe_checkout_intent_id);
        }
    }

    public static function interfaceLocales(): array
    {
        return array_map(static fn (string $locale): array => [$locale], ['en', 'es', 'pt-BR', 'fr', 'de', 'ja', 'ko', 'id', 'zh-CN']);
    }

    #[DataProvider('invalidLookupPages')]
    public function test_incomplete_or_invalid_lookup_cannot_clear_an_expired_unknown_intent(array $page): void
    {
        $user = $this->unknownCheckout();
        $user->update(['stripe_checkout_expires_at' => now()->subHour()]);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions*' => Http::response($page)]);

        $this->actingAs($user)->post(route('billing.checkout', ['planCode' => 'base']))->assertSessionHas('billing_error');

        $this->assertSame('intent_recovery', $user->fresh()->stripe_checkout_intent_id);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public static function invalidLookupPages(): array
    {
        return [
            'missing completeness flag' => [['data' => []]],
            'empty unfinished page' => [['data' => [], 'has_more' => true]],
            'wrong customer' => [['data' => [['id' => 'cs_other', 'customer' => 'cus_other']], 'has_more' => false]],
            'repeated cursor' => [['data' => [['id' => 'cs_repeat', 'customer' => 'cus_recovery']], 'has_more' => true]],
            'ambiguous intent' => [['data' => [
                ['id' => 'cs_one', 'customer' => 'cus_recovery', 'mode' => 'subscription', 'metadata' => ['checkout_intent_id' => 'intent_recovery']],
                ['id' => 'cs_two', 'customer' => 'cus_recovery', 'mode' => 'subscription', 'metadata' => ['checkout_intent_id' => 'intent_recovery']],
            ], 'has_more' => false]],
        ];
    }

    private function unknownCheckout(): User
    {
        config(['billing.plans.base.stripe_price_id' => 'price_base']);

        return User::factory()->create([
            'stripe_customer_id' => 'cus_recovery',
            'stripe_checkout_intent_id' => 'intent_recovery',
            'stripe_checkout_plan_code' => 'base',
            'stripe_checkout_session_id' => null,
            'stripe_checkout_session_url' => null,
            'stripe_checkout_expires_at' => now()->addMinutes(29),
        ]);
    }

    private function checkoutSession(string $status): array
    {
        return [
            'id' => 'cs_recovered',
            'customer' => 'cus_recovery',
            'mode' => 'subscription',
            'metadata' => ['checkout_intent_id' => 'intent_recovery'],
            'status' => $status,
            'url' => $status === 'open' ? 'https://checkout.stripe.test/recovered' : null,
        ];
    }
}
