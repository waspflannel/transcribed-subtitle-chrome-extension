<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\BillingUsageEvent;
use App\Models\StripeWebhookEvent;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use App\Services\Billing\UsageLedger;
use App\Services\Subtitles\SubtitleJobFailureHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingAndUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_start_checkout_and_open_billing_portal_through_hosted_stripe_flows(): void
    {
        config([
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.base.stripe_price_id' => 'price_base',
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

    public function test_webhooks_cover_checkout_failed_payment_cancellation_and_plan_change(): void
    {
        config([
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_456']);
        $periodStart = now()->startOfMonth()->timestamp;
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->timestamp;

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

    public function test_generation_denies_unavailable_feature_concurrency_and_exhausted_minutes(): void
    {
        Queue::fake();

        $this
            ->withExtensionAuth($this->installId('f'))
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['enrichmentMode' => 'full']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'feature_unavailable');

        config(['subtitles.tiers.plans.base.generation_concurrency' => 1]);
        $user = User::factory()->create();
        $this->withExtensionAuth($this->installId('c'), $user);
        SubtitleJob::factory()->for($user)->create(['status' => 'running']);

        $this
            ->withExtensionAuth($this->installId('c'), $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'concur00001']))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'concurrency_exceeded');

        config(['billing.plans.base.monthly_minutes' => 2]);
        $exhaustedUser = User::factory()->create();

        $this
            ->withExtensionAuth($this->installId('u'), $exhaustedUser)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'usage000001']))
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'usage_exhausted');
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
            'processing_version' => 'scribe-v2-tokenizer-v8-async-on-demand-romanized',
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

    public function test_generation_concurrency_rejection_log_is_sanitized(): void
    {
        config(['subtitles.tiers.plans.base.generation_concurrency' => 1]);
        Queue::fake();
        Log::spy();
        $installId = $this->installId('l');
        $user = User::factory()->create();
        SubtitleJob::factory()->for($user)->create(['status' => 'running']);

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'loglimit001']))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'concurrency_exceeded');

        Log::shouldHaveReceived('warning')
            ->with('backend.generation_concurrency_rejected', \Mockery::on(
                fn (array $context): bool => isset($context['user_hash'])
                    && $context['queue_family'] === 'generation'
                    && $context['limiter_type'] === 'generation_admission'
                    && $context['generation_tier'] === 'base'
                    && $context['concurrency_limit'] === 1
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
     * @return array<string, mixed>
     */
    private function subscriptionObject(
        User $user,
        string $priceId,
        string $status,
        int $periodStart,
        int $periodEnd,
    ): array {
        return [
            'id' => 'sub_456',
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
            'metadata' => ['user_id' => (string) $user->id],
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
