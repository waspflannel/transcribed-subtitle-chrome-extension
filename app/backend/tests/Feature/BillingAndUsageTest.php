<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Jobs\AcquireSubtitleAudio;
use App\Models\BillingUsageEvent;
use App\Models\StripeWebhookEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\StripeClient;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class BillingAndUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_summary_uses_one_query_and_preserves_scoped_signed_totals(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $start = now()->startOfMonth()->toImmutable();
        $end = $start->addMonth();
        $ledger = app(UsageLedger::class);

        $this->assertSame(['available' => 0, 'reserved' => 0, 'used' => 0], $ledger->summary($user, $start, $end));

        foreach ([[100, 0, 0], [0, 20, 0], [-5, -5, 5], [0, -2, 0]] as $index => [$available, $reserved, $used]) {
            BillingUsageEvent::create([
                'user_id' => $user->id,
                'plan_code' => 'base',
                'event_type' => 'adjustment',
                'billing_period_start' => $start,
                'billing_period_end' => $end,
                'available_minutes_delta' => $available,
                'reserved_minutes_delta' => $reserved,
                'used_minutes_delta' => $used,
                'idempotency_key' => 'summary-'.$index,
            ]);
        }
        $event = BillingUsageEvent::query()->firstOrFail();
        $event->replicate()->fill(['user_id' => $otherUser->id, 'idempotency_key' => 'other-user'])->save();
        $event->replicate()->fill(['billing_period_start' => $start->subMonth(), 'idempotency_key' => 'other-start'])->save();
        $event->replicate()->fill(['billing_period_end' => $end->addMonth(), 'idempotency_key' => 'other-end'])->save();

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame(['available' => 82, 'reserved' => 13, 'used' => 5], $ledger->summary($user, $start, $end));
            $this->assertCount(1, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        BillingUsageEvent::query()->whereBelongsTo($user)->update([
            'available_minutes_delta' => -10,
            'reserved_minutes_delta' => -2,
            'used_minutes_delta' => -3,
        ]);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'used' => 0], $ledger->summary($user, $start, $end));
    }

    public function test_user_can_start_checkout_and_open_billing_portal_through_hosted_stripe_flows(): void
    {
        config([
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.base.stripe_price_id' => 'price_base',
            'billing.stripe.portal_configuration' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.test/session',
            ]),
            'https://api.stripe.com/v1/billing_portal/sessions' => Http::response([
                'id' => 'bps_123',
                'url' => 'https://billing.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No active plan')
            ->assertSee('Base');

        $this
            ->actingAs($user)
            ->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/session');

        $this->assertSame('cus_123', $user->fresh()->stripe_customer_id);

        $this
            ->actingAs($user->fresh())
            ->post(route('billing.portal'))
            ->assertRedirect('https://billing.stripe.test/session');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
            && $request['customer'] === 'cus_123'
            && ! isset($request['configuration'])
            && $request['return_url'] === route('dashboard'));
    }

    public function test_billing_portal_uses_an_explicit_configuration_when_provided(): void
    {
        config([
            'billing.stripe.secret' => 'sk_test_123',
            'billing.stripe.portal_configuration' => 'bpc_test_subscription_management',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_configured']),
            'https://api.stripe.com/v1/billing_portal/sessions' => Http::response([
                'id' => 'bps_configured',
                'url' => 'https://billing.stripe.test/configured-session',
            ]),
        ]);

        $this
            ->actingAs(User::factory()->create())
            ->post(route('billing.portal'))
            ->assertRedirect('https://billing.stripe.test/configured-session');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
            && $request['configuration'] === 'bpc_test_subscription_management');
    }

    public function test_checkout_requests_reuse_one_bounded_intent_and_rotate_after_expiry(): void
    {
        config([
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.base.stripe_price_id' => 'price_base',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::sequence()
                ->push([
                    'id' => 'cs_test_123',
                    'url' => 'https://checkout.stripe.test/session',
                ])
                ->push([
                    'id' => 'cs_test_456',
                    'url' => 'https://checkout.stripe.test/second-session',
                ]),
        ]);
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/session');

        $this
            ->actingAs($user->fresh())
            ->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/session');

        $firstIntentId = $user->fresh()->stripe_checkout_intent_id;

        $this->assertNotNull($firstIntentId);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/customers'
            && str_starts_with((string) $request->header('Idempotency-Key')[0], 'tse-v1-'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && str_starts_with((string) $request->header('Idempotency-Key')[0], 'tse-v1-')
            && $request['metadata']['checkout_intent_id'] === $firstIntentId
            && $request['subscription_data']['metadata']['checkout_intent_id'] === $firstIntentId);

        $user->forceFill(['stripe_checkout_expires_at' => now()->subMinute()])->save();

        $this
            ->actingAs($user->fresh())
            ->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/second-session');

        $this->assertNotSame($firstIntentId, $user->fresh()->stripe_checkout_intent_id);
        Http::assertSentCount(3);

        $checkoutRequests = collect(Http::recorded())
            ->filter(fn (array $exchange): bool => $exchange[0]->url() === 'https://api.stripe.com/v1/checkout/sessions')
            ->values();

        $this->assertCount(2, $checkoutRequests);
        $this->assertNotSame(
            $checkoutRequests[0][0]->header('Idempotency-Key')[0],
            $checkoutRequests[1][0]->header('Idempotency-Key')[0],
        );
    }

    public function test_competing_first_customer_creations_share_one_stripe_idempotency_identity(): void
    {
        config(['billing.stripe.secret' => 'sk_test_123']);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
        ]);
        $user = User::factory()->create();

        $this->assertSame('cus_123', app(StripeClient::class)->createCustomer($user));
        $this->assertSame('cus_123', app(StripeClient::class)->createCustomer($user));

        $customerRequests = collect(Http::recorded());

        $this->assertCount(2, $customerRequests);
        $this->assertSame(
            $customerRequests[0][0]->header('Idempotency-Key')[0],
            $customerRequests[1][0]->header('Idempotency-Key')[0],
        );
    }

    public function test_checkout_is_blocked_for_a_non_terminal_subscription(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create([
            'stripe_subscription_id' => 'sub_existing',
            'billing_subscription_status' => 'past_due',
        ]);

        $this
            ->actingAs($user)
            ->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirectToRoute('dashboard')
            ->assertSessionHas('billing_status', 'Your existing subscription is managed in Stripe. Use Manage billing to change it.');

        $this
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Change an existing subscription in Stripe.')
            ->assertDontSee('Checkout opens in Stripe.');

        Http::assertNothingSent();
    }

    public function test_checkout_is_only_available_after_a_terminal_subscription_status(): void
    {
        $billing = app(BillingEntitlementService::class);

        foreach (['active', 'trialing', 'past_due', 'unpaid', 'incomplete'] as $status) {
            $user = User::factory()->make([
                'stripe_subscription_id' => 'sub_'.$status,
                'billing_subscription_status' => $status,
            ]);

            $this->assertTrue($billing->subscriptionRequiresPortal($user), "Expected {$status} to use the billing portal.");
        }

        foreach (['canceled', 'incomplete_expired'] as $status) {
            $user = User::factory()->make([
                'stripe_subscription_id' => 'sub_'.$status,
                'billing_subscription_status' => $status,
            ]);

            $this->assertFalse($billing->subscriptionRequiresPortal($user), "Expected {$status} to allow a new checkout.");
        }
    }

    public function test_dashboard_billing_box_offers_cancellation_for_active_subscriptions(): void
    {
        $user = User::factory()->create([
            'stripe_subscription_id' => 'sub_active',
            'billing_subscription_status' => 'active',
        ]);

        $this
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeText('Manage or cancel subscription')
            ->assertSeeText('cancel anytime');
    }

    public function test_dashboard_billing_box_shows_plain_manage_button_without_a_subscription(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_invoice_only']);

        $this
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeText('Manage billing')
            ->assertSeeText('No active subscription.')
            ->assertDontSeeText('Manage or cancel subscription');
    }

    public function test_stripe_webhook_updates_subscription_state_and_replay_does_not_duplicate_grants(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_123']);
        $periodStart = now()->startOfMonth()->timestamp;
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $payload = $this->stripePayload([
            'id' => 'evt_subscription_updated',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_123',
                    'customer' => 'cus_123',
                    'status' => 'active',
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'cancel_at_period_end' => false,
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_123',
                                'price' => ['id' => 'price_plus'],
                            ],
                        ],
                    ],
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ]);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], $this->stripeHeaders($payload), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], $this->stripeHeaders($payload), $payload)
            ->assertOk();

        $user = $user->fresh();
        $this->assertSame('sub_123', $user->stripe_subscription_id);
        $this->assertSame('si_123', $user->stripe_subscription_item_id);
        $this->assertSame('plus', $user->billing_plan_code);
        $this->assertSame('active', $user->billing_subscription_status);
        $this->assertSame(1, StripeWebhookEvent::count());
        $this->assertSame(1, BillingUsageEvent::query()->where('event_type', 'monthly_grant')->count());
        $this->assertSame(240, (int) BillingUsageEvent::query()->sum('available_minutes_delta'));
    }

    public function test_webhook_rejects_invalid_signatures(): void
    {
        config(['billing.stripe.webhook_secret' => 'whsec_test']);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid',
            ], $this->stripePayload(['id' => 'evt_bad', 'type' => 'checkout.session.completed']))
            ->assertStatus(400);

        $this->assertSame(0, StripeWebhookEvent::count());
    }

    public function test_handled_webhook_processing_failures_are_recorded_for_retry_debugging(): void
    {
        config(['billing.stripe.webhook_secret' => 'whsec_test']);
        $payload = $this->stripePayload([
            'id' => 'evt_missing_user',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_missing_user',
                    'customer' => 'cus_missing_user',
                    'status' => 'active',
                    'current_period_start' => now()->startOfMonth()->timestamp,
                    'current_period_end' => now()->addMonthNoOverflow()->startOfMonth()->timestamp,
                ],
            ],
        ]);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], $this->stripeHeaders($payload), $payload)
            ->assertStatus(500);

        $event = StripeWebhookEvent::query()->firstOrFail();
        $this->assertSame('evt_missing_user', $event->stripe_event_id);
        $this->assertNull($event->processed_at);
        $this->assertStringContainsString('did not match a local user', (string) $event->processing_error);
    }

    public function test_subscription_deleted_webhook_for_a_deleted_account_is_acknowledged_without_retry(): void
    {
        config(['billing.stripe.webhook_secret' => 'whsec_test']);
        $payload = $this->stripePayload([
            'id' => 'evt_deleted_account',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_deleted_account',
                    'customer' => 'cus_deleted_account',
                    'status' => 'canceled',
                ],
            ],
        ]);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], $this->stripeHeaders($payload), $payload)
            ->assertOk();

        $event = StripeWebhookEvent::query()->firstOrFail();
        $this->assertSame('evt_deleted_account', $event->stripe_event_id);
        $this->assertNotNull($event->processed_at);
        $this->assertNull($event->processing_error);
    }

    public function test_stale_subscription_updated_does_not_overwrite_newer_period_and_status(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_789']);

        $newerStart = now()->startOfMonth()->timestamp;
        $newerEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $olderStart = now()->subMonthNoOverflow()->startOfMonth()->timestamp;
        $olderEnd = now()->startOfMonth()->timestamp;

        $newerCreatedAt = now()->timestamp;
        $olderCreatedAt = now()->subHour()->timestamp;

        $this->postStripeEventWithCreated('evt_newer', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_pro',
            status: 'active',
            periodStart: $newerStart,
            periodEnd: $newerEnd,
        ), $newerCreatedAt);

        $this->postStripeEventWithCreated('evt_older', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'past_due',
            periodStart: $olderStart,
            periodEnd: $olderEnd,
        ), $olderCreatedAt);

        $user = $user->fresh();
        $this->assertSame('pro', $user->billing_plan_code);
        $this->assertSame('active', $user->billing_subscription_status);
        $this->assertSame($newerStart, $user->billing_current_period_start->timestamp);
        $this->assertSame($newerEnd, $user->billing_current_period_end->timestamp);
    }

    public function test_subscription_updated_first_event_null_marker_applies(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_null_marker']);
        $periodStart = now()->startOfMonth()->timestamp;
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $createdAt = now()->timestamp;

        $this->assertNull($user->fresh()->billing_subscription_event_at);

        $this->postStripeEventWithCreated('evt_first', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ), $createdAt);

        $user = $user->fresh();
        $this->assertSame('plus', $user->billing_plan_code);
        $this->assertSame('active', $user->billing_subscription_status);
        $this->assertSame($createdAt, $user->billing_subscription_event_at->timestamp);
        $this->assertSame(1, BillingUsageEvent::query()->where('event_type', 'monthly_grant')->count());
    }

    public function test_subscription_updated_in_order_applies_each_event(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_inorder']);

        $firstStart = now()->subMonthNoOverflow()->startOfMonth()->timestamp;
        $firstEnd = now()->startOfMonth()->timestamp;
        $secondStart = now()->startOfMonth()->timestamp;
        $secondEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;

        $this->postStripeEventWithCreated('evt_first', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $firstStart,
            periodEnd: $firstEnd,
        ), now()->subMonthNoOverflow()->startOfMonth()->timestamp);

        $this->postStripeEventWithCreated('evt_second', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_pro',
            status: 'active',
            periodStart: $secondStart,
            periodEnd: $secondEnd,
        ), now()->startOfMonth()->timestamp);

        $user = $user->fresh();
        $this->assertSame('pro', $user->billing_plan_code);
        $this->assertSame('active', $user->billing_subscription_status);
        $this->assertSame($secondStart, $user->billing_current_period_start->timestamp);
        $this->assertSame($secondEnd, $user->billing_current_period_end->timestamp);
    }

    public function test_same_second_subscription_updates_refresh_authoritative_state(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_456']);
        $periodStart = now()->startOfMonth()->timestamp;
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $createdAt = now()->timestamp;
        $authoritative = $this->subscriptionObject(
            user: $user,
            priceId: 'price_pro',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_456' => Http::response($authoritative),
        ]);

        $this->postStripeEventWithCreated('evt_first_update', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ), $createdAt);
        $this->postStripeEventWithCreated('evt_second_update', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ), $createdAt);

        $user = $user->fresh();
        $this->assertSame('pro', $user->billing_plan_code);
        $this->assertSame('customer.subscription.updated', $user->billing_subscription_event_type);

        $this->postStripeEventWithCreated('evt_third_update', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ), $createdAt);

        $this->assertSame('pro', $user->fresh()->billing_plan_code);
        Http::assertSentCount(2);
    }

    public function test_new_subscription_event_can_arrive_before_checkout_completion_after_cancellation(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
        ]);
        $intentId = '8fdd75c7-6584-4af5-aae7-44e27f14fb91';
        $createdAt = now()->timestamp;
        $periodStart = now()->startOfMonth()->timestamp;
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_456',
            'stripe_subscription_id' => 'sub_canceled',
            'billing_plan_code' => 'base',
            'billing_subscription_status' => 'canceled',
            'billing_subscription_event_at' => now()->subMinute(),
            'stripe_checkout_intent_id' => $intentId,
            'stripe_checkout_plan_code' => 'plus',
            'stripe_checkout_expires_at' => now()->addMinutes(20),
        ]);
        $authoritative = $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            subscriptionId: 'sub_new',
            checkoutIntentId: $intentId,
        );
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_new' => Http::response($authoritative),
        ]);

        $this->postStripeEventWithCreated(
            'evt_new_subscription',
            'customer.subscription.created',
            $authoritative,
            $createdAt,
        );

        $user = $user->fresh();
        $this->assertSame('sub_new', $user->stripe_subscription_id);
        $this->assertSame('active', $user->billing_subscription_status);
        $this->assertSame('plus', $user->billing_plan_code);
        $this->assertNull($user->stripe_checkout_intent_id);

        $this->postStripeEventWithCreated('evt_new_checkout', 'checkout.session.completed', [
            'id' => 'cs_new',
            'customer' => 'cus_456',
            'subscription' => 'sub_new',
            'client_reference_id' => (string) $user->id,
            'metadata' => ['plan_code' => 'plus', 'user_id' => (string) $user->id, 'checkout_intent_id' => $intentId],
        ], $createdAt);

        $this->assertSame('sub_new', $user->fresh()->stripe_subscription_id);
        $this->assertSame('active', $user->fresh()->billing_subscription_status);
    }

    public function test_old_subscription_webhooks_do_not_replace_the_current_subscription(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_current',
            'stripe_subscription_id' => 'sub_current',
            'billing_plan_code' => 'pro',
            'billing_subscription_status' => 'active',
        ]);

        $this->postStripeEvent('evt_old_checkout', 'checkout.session.completed', [
            'id' => 'cs_old',
            'customer' => 'cus_old',
            'subscription' => 'sub_old',
            'client_reference_id' => (string) $user->id,
            'metadata' => ['plan_code' => 'base', 'user_id' => (string) $user->id],
        ]);
        $this->postStripeEvent('evt_old_invoice', 'invoice.payment_failed', [
            'customer' => 'cus_current',
            'subscription' => 'sub_old',
        ]);
        $this->postStripeEvent('evt_old_subscription', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_pro',
            status: 'past_due',
            periodStart: now()->startOfMonth()->timestamp,
            periodEnd: now()->addMonthNoOverflow()->startOfMonth()->timestamp,
            subscriptionId: 'sub_old',
        ));

        $user = $user->fresh();
        $this->assertSame('sub_current', $user->stripe_subscription_id);
        $this->assertSame('active', $user->billing_subscription_status);
        $this->assertSame('pro', $user->billing_plan_code);
    }

    public function test_webhooks_cover_checkout_failed_payment_cancellation_and_plan_change(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_456']);
        $periodStart = now()->startOfMonth()->timestamp;
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;
        $authoritativeStatus = 'active';
        $authoritativePriceId = 'price_plus';
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($user, $periodStart, $periodEnd, &$authoritativeStatus, &$authoritativePriceId) {
            if ($request->url() !== 'https://api.stripe.com/v1/subscriptions/sub_456') {
                return Http::response([], 404);
            }

            return Http::response($this->subscriptionObject(
                user: $user,
                priceId: $authoritativePriceId,
                status: $authoritativeStatus,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            ));
        });

        $this->postStripeEvent('evt_checkout', 'checkout.session.completed', [
            'id' => 'cs_456',
            'customer' => 'cus_456',
            'subscription' => 'sub_456',
            'client_reference_id' => (string) $user->id,
            'metadata' => ['plan_code' => 'plus', 'user_id' => (string) $user->id],
        ]);
        $this->postStripeEvent('evt_plus', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_plus',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ));
        $this->postStripeEvent('evt_pro', 'customer.subscription.updated', $this->subscriptionObject(
            user: $user,
            priceId: 'price_pro',
            status: 'active',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ));

        $this->assertSame('pro', $user->fresh()->billing_plan_code);
        $this->assertSame(600, (int) BillingUsageEvent::query()->where('event_type', 'monthly_grant')->sum('available_minutes_delta'));

        $authoritativeStatus = 'past_due';
        $authoritativePriceId = 'price_pro';
        $this->postStripeEvent('evt_failed', 'invoice.payment_failed', [
            'customer' => 'cus_456',
            'subscription' => 'sub_456',
        ]);
        $this->assertSame('past_due', $user->fresh()->billing_subscription_status);

        $this->postStripeEvent('evt_deleted', 'customer.subscription.deleted', $this->subscriptionObject(
            user: $user,
            priceId: 'price_pro',
            status: 'canceled',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        ));
        $this->assertSame('canceled', $user->fresh()->billing_subscription_status);
    }

    public function test_generation_requires_active_subscription(): void
    {
        Queue::fake();
        $installId = $this->installId();
        $user = User::factory()->create();
        $token = app(ExtensionTokenIssuer::class)->issue($user, $installId);

        $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'payment_required');
    }

    public function test_generation_reserves_debits_and_reuses_cached_tracks_without_double_charging(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $installId = $this->installId();
        $response = $this
            ->withExtensionAuth($installId)
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();
        $job = SubtitleJob::query()->where('public_id', $response->json('jobId'))->firstOrFail();
        $user = $job->user;

        $this->assertSame(4, (int) BillingUsageEvent::query()->where('event_type', 'reservation')->sum('reserved_minutes_delta'));

        $track = SubtitleTrack::factory()->for($job, 'job')->create([
            'youtube_video_id' => $job->youtube_video_id,
            'processing_version' => $job->processing_version,
            'expires_at' => now()->addDays(30),
        ]);
        $job->forceFill([
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'expires_at' => $track->expires_at,
        ])->save();
        app(UsageLedger::class)->debitCompletedJob($job->refresh()->load('user'), $track);

        $this
            ->withExtensionAuth($installId, $user)
            ->getJson('/v1/extension-auth/account')
            ->assertOk()
            ->assertJsonPath('account.monthlyMinutesUsed', 4)
            ->assertJsonPath('account.monthlyMinutesPending', 0);

        $eventCount = BillingUsageEvent::count();

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('track.trackId', $track->public_id);

        $this->assertSame($eventCount, BillingUsageEvent::count());
    }

    public function test_failed_generation_releases_reserved_minutes(): void
    {
        config([
            'queue.default' => 'database',
            'subtitles.queue.connection' => 'database',
        ]);
        Queue::fake();

        $response = $this
            ->withExtensionAuth($this->installId())
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();
        $job = SubtitleJob::query()->where('public_id', $response->json('jobId'))->firstOrFail();

        app(SubtitleJobFailureHandler::class)->failJob(
            $job->id,
            'transcribing',
            SubtitleProcessingException::transcriptionFailed(),
            $job->run_id,
        );

        $this->assertSame(0, app(UsageLedger::class)->reservedMinutesForJob($job));
        $this->assertSame(-4, (int) BillingUsageEvent::query()->where('event_type', 'refund')->sum('reserved_minutes_delta'));
    }

    public function test_release_wins_one_terminal_settlement_when_completion_arrives_afterward(): void
    {
        $user = $this->subscribedUser('base');
        $job = SubtitleJob::factory()->for($user)->create([
            'run_id' => 'a5d7c6fd-728c-44cc-8557-3a7e4b352d78',
            'video_duration_seconds' => 60,
        ]);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $ledger = app(UsageLedger::class);
        $ledger->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 1);

        $ledger->releaseReservation($job->load('user'), 'deleted');
        $ledger->debitCompletedJob($job->fresh()->load('user'), $track);

        $terminalEvents = BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->whereIn('event_type', ['debit', 'refund'])
            ->get();

        $this->assertCount(1, $terminalEvents);
        $this->assertSame('refund', $terminalEvents->sole()->event_type);
        $this->assertSame('settlement:'.$job->id.':'.$job->run_id, $terminalEvents->sole()->idempotency_key);
        $this->assertSame(0, $ledger->reservedMinutesForJob($job));
        $this->assertSame(0, (int) $terminalEvents->sum('used_minutes_delta'));
    }

    public function test_completion_wins_one_terminal_settlement_when_release_arrives_afterward(): void
    {
        $user = $this->subscribedUser('base');
        $job = SubtitleJob::factory()->for($user)->create([
            'run_id' => '0bf6ac04-158e-4c5b-916e-ef97770a03dc',
            'video_duration_seconds' => 60,
        ]);
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $ledger = app(UsageLedger::class);
        $ledger->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 1);

        $ledger->debitCompletedJob($job->load('user'), $track);
        $ledger->releaseReservation($job->fresh()->load('user'), 'failure');

        $terminalEvents = BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->whereIn('event_type', ['debit', 'refund'])
            ->get();

        $this->assertCount(1, $terminalEvents);
        $this->assertSame('debit', $terminalEvents->sole()->event_type);
        $this->assertSame('settlement:'.$job->id.':'.$job->run_id, $terminalEvents->sole()->idempotency_key);
        $this->assertSame(0, $ledger->reservedMinutesForJob($job));
        $this->assertSame(1, (int) $terminalEvents->sum('used_minutes_delta'));
    }

    public function test_job_settlement_uses_its_original_reservation_period_after_a_subscription_renewal(): void
    {
        $originalStart = now()->subMonthNoOverflow()->startOfMonth()->toImmutable();
        $originalEnd = now()->startOfMonth()->toImmutable();
        $renewedStart = now()->startOfMonth()->toImmutable();
        $renewedEnd = now()->addMonthNoOverflow()->startOfMonth()->toImmutable();
        $user = User::factory()->create([
            'stripe_subscription_id' => 'sub_original',
            'billing_plan_code' => 'base',
            'billing_subscription_status' => 'active',
            'billing_current_period_start' => $originalStart,
            'billing_current_period_end' => $originalEnd,
        ]);
        $job = SubtitleJob::factory()->for($user)->create([
            'run_id' => '4b32e0fb-5dde-4ff6-b800-1e5d043d65a9',
            'video_duration_seconds' => 60,
        ]);
        $ledger = app(UsageLedger::class);
        $plan = app(BillingPlanCatalog::class)->requirePlan('base');
        $ledger->ensureMonthlyGrant($user, $plan, $originalStart, $originalEnd);
        $ledger->reserveForJob($job, $user, $plan, 1);

        $user->forceFill([
            'stripe_subscription_id' => 'sub_renewed',
            'billing_plan_code' => 'pro',
            'billing_current_period_start' => $renewedStart,
            'billing_current_period_end' => $renewedEnd,
        ])->save();
        $job->forceFill(['video_duration_seconds' => 125])->save();

        app(BillingEntitlementService::class)->syncJobReservationToActualDuration($job->fresh());
        $track = SubtitleTrack::factory()->for($job, 'job')->create();
        $ledger->debitCompletedJob($job->fresh()->load('user'), $track);

        $events = BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->get();

        $this->assertCount(3, $events);
        $this->assertTrue($events->every(fn (BillingUsageEvent $event): bool => $event->billing_period_start->equalTo($originalStart)));
        $this->assertTrue($events->every(fn (BillingUsageEvent $event): bool => $event->billing_period_end->equalTo($originalEnd)));
        $this->assertTrue($events->every(fn (BillingUsageEvent $event): bool => $event->stripe_subscription_id === 'sub_original'));
        $this->assertSame(0, (int) BillingUsageEvent::query()
            ->where('subtitle_job_id', $job->id)
            ->where('billing_period_start', $renewedStart)
            ->count());
    }

    public function test_generation_denies_unavailable_feature_full_queue_and_exhausted_minutes(): void
    {
        Queue::fake();

        $this
            ->withExtensionAuth($this->installId('f'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'feature_unavailable');

        config([
            'subtitles.tiers.plans.base.generation_concurrency' => 1,
            'subtitles.tiers.plans.base.submission_limit' => 1,
        ]);
        $user = User::factory()->create();
        $this->withExtensionAuth($this->installId('c'), $user);
        SubtitleJob::factory()->for($user)->create(['status' => 'running']);

        $this
            ->withExtensionAuth($this->installId('c'), $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'concur00001']))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'queue_full');

        config(['billing.plans.base.monthly_minutes' => 2]);
        $exhaustedUser = User::factory()->create();

        $this
            ->withExtensionAuth($this->installId('u'), $exhaustedUser)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'usage000001']))
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'usage_exhausted');
    }

    public function test_full_word_cards_feature_must_be_explicitly_configured(): void
    {
        config(['billing.plans.base.features' => []]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Billing plan [base] must define feature [full_word_cards].');

        $plans = app(BillingPlanCatalog::class);
        $plans->supportsFullWordCards($plans->requirePlan('base'));
    }

    public function test_submission_over_processing_concurrency_is_queued_not_rejected(): void
    {
        config([
            'subtitles.tiers.plans.base.generation_concurrency' => 1,
            'subtitles.tiers.plans.base.submission_limit' => 3,
        ]);
        Queue::fake();
        $user = User::factory()->create();
        SubtitleJob::factory()->for($user)->create(['status' => 'running']);

        $response = $this
            ->withExtensionAuth($this->installId('q'), $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'queuedjob01']))
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('stage', 'preparing')
            ->assertJsonPath('progressPercent', 0);

        Queue::assertNothingPushed();

        // The queued job holds its minute reservation from submission time.
        $job = SubtitleJob::query()->where('public_id', $response->json('jobId'))->firstOrFail();
        $this->assertGreaterThan(0, app(UsageLedger::class)->reservedMinutesForJob($job));
    }

    public function test_finished_job_promotes_the_oldest_queued_job_fifo(): void
    {
        config([
            'subtitles.tiers.plans.base.generation_concurrency' => 1,
            'subtitles.tiers.plans.base.submission_limit' => 5,
        ]);
        Queue::fake();
        $user = User::factory()->create();
        $running = SubtitleJob::factory()->for($user)->create([
            'status' => 'running',
            'stage' => 'transcribing',
        ]);
        $firstQueued = SubtitleJob::factory()->for($user)->create([
            'status' => 'queued',
            'stage' => 'preparing',
            'progress_percent' => 0,
        ]);
        $secondQueued = SubtitleJob::factory()->for($user)->create([
            'status' => 'queued',
            'stage' => 'preparing',
            'progress_percent' => 0,
        ]);

        app(SubtitleJobFailureHandler::class)->failJob(
            $running->id,
            'transcribing',
            SubtitleProcessingException::transcriptionFailed(),
            $running->run_id,
        );

        $this->assertSame('running', $firstQueued->fresh()->status);
        $this->assertSame(5, $firstQueued->fresh()->progress_percent);
        $this->assertSame('queued', $secondQueued->fresh()->status);
        Queue::assertPushed(
            AcquireSubtitleAudio::class,
            fn (AcquireSubtitleAudio $job): bool => $job->subtitleJobId === $firstQueued->id
                && $job->runId === $firstQueued->run_id,
        );
    }

    public function test_queue_promotion_uses_current_submission_time_before_row_id(): void
    {
        config([
            'subtitles.tiers.plans.base.generation_concurrency' => 1,
            'subtitles.tiers.plans.base.submission_limit' => 5,
        ]);
        Queue::fake();
        $user = User::factory()->create();
        $running = SubtitleJob::factory()->for($user)->create(['status' => 'running']);
        $laterSubmission = SubtitleJob::factory()->for($user)->create([
            'status' => 'queued',
            'stage' => 'preparing',
            'progress_percent' => 0,
        ]);
        $earlierRetry = SubtitleJob::factory()->for($user)->create([
            'status' => 'queued',
            'stage' => 'preparing',
            'progress_percent' => 0,
        ]);
        $laterSubmission->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
        $earlierRetry->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();

        app(SubtitleJobFailureHandler::class)->failJob(
            $running->id,
            'preparing',
            SubtitleProcessingException::enrichmentFailed(),
            $running->run_id,
        );

        $this->assertSame('running', $earlierRetry->fresh()->status);
        $this->assertSame('queued', $laterSubmission->fresh()->status);
        Queue::assertPushed(
            AcquireSubtitleAudio::class,
            fn (AcquireSubtitleAudio $job): bool => $job->subtitleJobId === $earlierRetry->id,
        );
    }

    public function test_compatible_running_generation_reuse_does_not_consume_another_generation_slot(): void
    {
        config(['subtitles.tiers.plans.base.generation_concurrency' => 1]);
        Queue::fake();
        $installId = $this->installId('r');
        $user = User::factory()->create();
        $payload = $this->validPayload(['youtubeVideoId' => 'reuse000001']);
        $runningJob = SubtitleJob::factory()->for($user)->create([
            'public_id' => '6f870f20-962c-4a55-b92d-046d7f004001',
            'youtube_video_id' => $payload['youtubeVideoId'],
            'youtube_url' => $payload['youtubeUrl'],
            'source_language' => $payload['sourceLanguage'],
            'target_language' => $payload['targetLanguage'],
            'processing_version' => 'scribe-v2-analysis-v15-on-demand-romanized',
            'enrichment_mode' => 'on_demand',
            'include_romanization' => true,
            'include_translation' => false,
            'status' => 'running',
            'stage' => 'tokenizing',
            'install_id' => $installId,
        ]);

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $payload)
            ->assertAccepted()
            ->assertJsonPath('jobId', $runningJob->public_id);

        Queue::assertNothingPushed();
    }

    public function test_completed_and_failed_generations_do_not_count_against_active_generation_limit(): void
    {
        config(['subtitles.tiers.plans.base.generation_concurrency' => 1]);
        Queue::fake();
        $installId = $this->installId('n');
        $user = User::factory()->create();
        SubtitleJob::factory()->for($user)->create([
            'youtube_video_id' => 'done0000001',
            'status' => 'completed',
            'stage' => 'finalizing',
        ]);
        SubtitleJob::factory()->for($user)->create([
            'youtube_video_id' => 'fail0000001',
            'status' => 'failed',
            'stage' => 'transcribing',
        ]);

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'newlimit001']))
            ->assertAccepted()
            ->assertJsonPath('status', 'running');
    }

    public function test_generation_queue_full_rejection_log_is_sanitized(): void
    {
        config([
            'subtitles.tiers.plans.base.generation_concurrency' => 1,
            'subtitles.tiers.plans.base.submission_limit' => 1,
        ]);
        Queue::fake();
        Log::spy();
        $installId = $this->installId('l');
        $user = User::factory()->create();
        SubtitleJob::factory()->for($user)->create(['status' => 'running']);

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'loglimit001']))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'queue_full');

        Log::shouldHaveReceived('warning')
            ->with('backend.generation_queue_full_rejected', \Mockery::on(
                fn (array $context): bool => isset($context['user_hash'])
                    && $context['queue_family'] === 'generation'
                    && $context['limiter_type'] === 'generation_admission'
                    && $context['generation_tier'] === 'base'
                    && $context['submission_limit'] === 1
                    && $context['observed_active_count'] === 1
                    && ! array_key_exists('user_id', $context)
                    && ! array_key_exists('install_id', $context),
            ));
    }

    public function test_support_adjustments_and_margin_report_are_inspectable(): void
    {
        $user = User::factory()->create();
        $this->withExtensionAuth($this->installId(), $user);

        Artisan::call('billing:adjust-usage', [
            'user' => (string) $user->id,
            'minutes' => '15',
            'note' => 'Beta support credit',
            '--created-by' => 'support@example.test',
        ]);
        $this->assertStringContainsString('Recorded 15 minute adjustment', Artisan::output());

        $this->assertDatabaseHas('billing_usage_events', [
            'user_id' => $user->id,
            'event_type' => 'adjustment',
            'available_minutes_delta' => 15,
            'created_by' => 'support@example.test',
            'note' => 'Beta support credit',
        ]);

        Artisan::call('billing:usage-report', ['--json' => true]);
        $this->assertStringContainsString('"plan": "base"', Artisan::output());
    }

    private function subscribedUser(string $planCode): User
    {
        $user = User::factory()->create();
        $periodStart = now()->startOfMonth()->toImmutable();
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->toImmutable();

        $user->forceFill([
            'stripe_customer_id' => 'cus_settlement_'.$user->id,
            'stripe_subscription_id' => 'sub_settlement_'.$user->id,
            'billing_plan_code' => $planCode,
            'billing_subscription_status' => 'active',
            'billing_current_period_start' => $periodStart,
            'billing_current_period_end' => $periodEnd,
        ])->save();

        app(UsageLedger::class)->ensureMonthlyGrant(
            $user->refresh(),
            app(BillingPlanCatalog::class)->requirePlan($planCode),
            $periodStart,
            $periodEnd,
        );

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function postStripeEvent(string $eventId, string $type, array $object): void
    {
        $payload = $this->stripePayload([
            'id' => $eventId,
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], $this->stripeHeaders($payload), $payload)
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function postStripeEventWithCreated(string $eventId, string $type, array $object, int $createdAt): void
    {
        $payload = $this->stripePayload([
            'id' => $eventId,
            'type' => $type,
            'created' => $createdAt,
            'data' => ['object' => $object],
        ]);

        $this
            ->call('POST', '/stripe/webhook', [], [], [], $this->stripeHeaders($payload), $payload)
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionObject(
        User $user,
        string $priceId,
        string $status,
        int $periodStart,
        int $periodEnd,
        string $subscriptionId = 'sub_456',
        ?string $checkoutIntentId = null,
    ): array {
        return [
            'id' => $subscriptionId,
            'customer' => 'cus_456',
            'status' => $status,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => $status === 'active' ? false : true,
            'items' => [
                'data' => [
                    [
                        'id' => 'si_456',
                        'price' => ['id' => $priceId],
                    ],
                ],
            ],
            'metadata' => [
                'user_id' => (string) $user->id,
                ...($checkoutIntentId === null ? [] : ['checkout_intent_id' => $checkoutIntentId]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        $videoId = (string) ($overrides['youtubeVideoId'] ?? 'dQw4w9WgXcQ');

        return [
            'youtubeVideoId' => $videoId,
            'youtubeUrl' => 'https://www.youtube.com/watch?v='.$videoId,
            'videoDurationSeconds' => 213,
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            'enrichmentMode' => 'on_demand',
            'includeRomanization' => true,
            'includeTranslation' => false,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stripePayload(array $payload): string
    {
        return json_encode([
            'livemode' => false,
            ...$payload,
        ], JSON_THROW_ON_ERROR);
    }

    private function stripeSignature(string $payload): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * @return array<string, string>
     */
    private function stripeHeaders(string $payload): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload),
        ];
    }

    private function installId(string $character = 'a'): string
    {
        return 'install_'.str_repeat($character, 32);
    }
}
