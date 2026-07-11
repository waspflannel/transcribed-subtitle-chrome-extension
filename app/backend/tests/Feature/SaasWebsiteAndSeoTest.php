<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SaasWebsiteAndSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_seo_metadata_and_beta_copy(): void
    {
        $pages = [
            ['marketing.home', 'actually watch', 'Only public YouTube watch pages and Shorts'],
            ['marketing.pricing', 'Simple monthly plans', 'Stripe'],
            ['marketing.privacy', 'Video-derived data', 'raw audio is deleted'],
            ['marketing.terms', 'Paid beta terms', 'Refund requests'],
            ['marketing.support', 'Help for beta access', 'failure code'],
        ];

        foreach ($pages as [$routeName, $heading, $copy]) {
            $this
                ->get(route($routeName, absolute: false))
                ->assertOk()
                ->assertSeeText($heading)
                ->assertSeeText($copy)
                ->assertSee('<meta name="description"', false)
                ->assertSee('<meta property="og:title"', false)
                ->assertSee('<meta name="twitter:card"', false)
                ->assertSee('<link rel="canonical" href="'.route($routeName).'">', false);
        }
    }

    public function test_robots_and_sitemap_expose_public_urls_only(): void
    {
        $this
            ->get('/robots.txt')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /dashboard')
            ->assertSee('Sitemap: '.route('sitemap'));

        $this
            ->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee(route('marketing.home'), false)
            ->assertSee(route('marketing.pricing'), false)
            ->assertSee(route('marketing.privacy'), false)
            ->assertDontSee(url('/extension'), false)
            ->assertDontSee(url('/languages'), false)
            ->assertDontSee(url('/how-it-works'), false)
            ->assertDontSee(url('/faq'), false)
            ->assertDontSee('/desktop', false)
            ->assertDontSee('/dashboard');
    }

    public function test_landing_page_shows_product_mock_pricing_and_plan_signup_links(): void
    {
        $this
            ->get(route('marketing.home', absolute: false))
            ->assertOk()
            ->assertSeeText('Learn a language from the videos you')
            ->assertSeeText('Click any word for an instant flashcard')
            ->assertSeeText('Supported subtitle and translation languages')
            ->assertSeeText('generated-video minutes')
            ->assertSee(route('register', ['plan' => 'base']), false)
            ->assertSee(route('register', ['plan' => 'plus']), false)
            ->assertSee(route('register', ['plan' => 'pro']), false);
    }

    public function test_retired_marketing_pages_redirect_to_landing_anchors(): void
    {
        foreach ([
            '/desktop' => '/#install',
            '/extension' => '/#install',
            '/languages' => '/#languages',
            '/how-it-works' => '/#how',
            '/faq' => '/#faq',
        ] as $from => $to) {
            $this
                ->get($from)
                ->assertMovedPermanently()
                ->assertRedirect($to);
        }
    }

    public function test_plan_choice_carries_through_registration_to_dashboard_checkout(): void
    {
        Notification::fake();

        $this
            ->get(route('register', ['plan' => 'plus'], absolute: false))
            ->assertOk()
            ->assertSeeText('Plus plan selected');

        $this
            ->post('/register', [
                'name' => 'Plan Carrier',
                'email' => 'plan-carrier@example.com',
                'password' => 'correct12345',
                'password_confirmation' => 'correct12345',
                'plan' => 'plus',
            ])
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('checkout_plan', 'plus');

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->withSession(['checkout_plan' => 'plus'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeText('Finish setting up your Plus plan')
            ->assertSeeText('Continue to checkout');

        config([
            'billing.stripe.secret' => 'sk_test_123',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_123']),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $this
            ->actingAs($user)
            ->withSession(['checkout_plan' => 'plus'])
            ->post(route('billing.checkout', ['planCode' => 'plus']))
            ->assertRedirect('https://checkout.stripe.test/session')
            ->assertSessionMissing('checkout_plan');
    }

    public function test_invalid_or_subscribed_plan_carry_shows_no_checkout_banner(): void
    {
        $this
            ->post('/register', [
                'name' => 'No Plan',
                'email' => 'no-plan@example.com',
                'password' => 'correct12345',
                'password_confirmation' => 'correct12345',
                'plan' => 'bogus',
            ])
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionMissing('checkout_plan');

        $subscribed = User::factory()->create([
            'billing_subscription_status' => 'active',
        ]);

        $this
            ->actingAs($subscribed)
            ->withSession(['checkout_plan' => 'plus'])
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSeeText('Finish setting up your Plus plan')
            ->assertSessionMissing('checkout_plan');
    }

    public function test_dashboard_is_protected_and_guides_extension_billing_usage_and_jobs(): void
    {
        $this
            ->get('/dashboard')
            ->assertRedirect(route('login', absolute: false));

        $user = User::factory()->create();

        SubtitleJob::factory()->for($user)->create([
            'status' => 'failed',
            'stage' => 'transcribing',
            'source_language' => 'spa',
            'target_language' => 'eng',
            'video_duration_seconds' => 125,
            'error_code' => 'transcription_failed',
            'error_message' => 'Transcription failed.',
        ]);

        $this
            ->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeText('Install extension')
            ->assertSeeText('Open YouTube')
            ->assertSeeText('Manage billing')
            ->assertSeeText('Recent jobs')
            ->assertSeeText('Spanish to English')
            ->assertSeeText('No connected extension installs.');
    }

    public function test_job_detail_is_owner_scoped_and_hides_generated_text(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'completed',
            'stage' => 'finalizing',
            'progress_percent' => 100,
            'source_language' => 'spa',
            'detected_source_language' => 'spa',
            'target_language' => 'eng',
            'video_duration_seconds' => 213,
        ]);
        SubtitleTrack::factory()->for($job, 'job')->create([
            'youtube_video_id' => $job->youtube_video_id,
            'source_language' => 'spa',
            'target_language' => 'eng',
            'web_vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nprivate generated text\n",
            'cues' => [
                [
                    'cueId' => 'cue-0001',
                    'index' => 0,
                    'startMs' => 1000,
                    'endMs' => 2000,
                    'sourceText' => 'private generated text',
                    'tokens' => [],
                ],
            ],
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard.jobs.show', ['jobId' => $job->public_id], absolute: false))
            ->assertOk()
            ->assertSeeText($job->public_id)
            ->assertSeeText('Spanish to English')
            ->assertSeeText('Billable minutes')
            ->assertSeeText('4')
            ->assertSeeText('Generated track')
            ->assertDontSee('private generated text');

        $this
            ->actingAs($otherUser)
            ->get(route('dashboard.jobs.show', ['jobId' => $job->public_id], absolute: false))
            ->assertNotFound();
    }

    public function test_failed_job_detail_shows_public_failure_code(): void
    {
        $user = User::factory()->create();
        $job = SubtitleJob::factory()->for($user)->create([
            'status' => 'failed',
            'stage' => 'transcribing',
            'source_language' => 'auto',
            'target_language' => 'eng',
            'video_duration_seconds' => 60,
            'error_code' => 'transcription_failed',
            'error_message' => 'Transcription failed.',
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard.jobs.show', ['jobId' => $job->public_id], absolute: false))
            ->assertOk()
            ->assertSeeText('Failure code')
            ->assertSeeText('transcription_failed')
            ->assertSeeText('Auto detect to English');
    }

    public function test_funnel_analytics_logs_sanitized_marketing_signup_checkout_and_extension_events(): void
    {
        Notification::fake();
        Log::spy();

        $this->get('/pricing')->assertOk();

        Log::shouldHaveReceived('info')
            ->with('analytics.marketing_page_view', Mockery::on(
                fn (array $context): bool => $context['page'] === 'pricing'
                    && $context['visitor_type'] === 'anonymous'
                    && ! array_key_exists('youtube_url', $context)
                    && ! array_key_exists('transcript', $context),
            ));

        $this
            ->post('/register', [
                'name' => 'Beta Learner',
                'email' => 'learner@example.com',
                'password' => 'correct12345',
                'password_confirmation' => 'correct12345',
            ])
            ->assertRedirect(route('verification.notice', absolute: false));

        Log::shouldHaveReceived('info')
            ->with('analytics.signup_completed', Mockery::on(
                fn (array $context): bool => isset($context['user_hash'])
                    && ! array_key_exists('email', $context),
            ));

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
        ]);

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('billing.checkout', ['planCode' => 'base']))
            ->assertRedirect('https://checkout.stripe.test/session');

        Log::shouldHaveReceived('info')
            ->with('analytics.checkout_started', Mockery::on(
                fn (array $context): bool => $context['plan_code'] === 'base'
                    && ! array_key_exists('email', $context),
            ));

        $this
            ->withHeader('X-Extension-Install-Id', $this->installId())
            ->postJson('/v1/extension-auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->with('analytics.extension_connected', Mockery::on(
                fn (array $context): bool => isset($context['install_hash'])
                    && ! array_key_exists('install_id', $context),
            ));
    }

    public function test_generation_funnel_analytics_marks_first_and_retention_without_video_content(): void
    {
        Queue::fake();
        Log::spy();
        config(['subtitles.tiers.plans.base.generation_concurrency' => 2]);

        $installId = $this->installId();
        $user = User::factory()->create();

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload())
            ->assertAccepted();

        Log::shouldHaveReceived('info')
            ->with('analytics.first_generation_started', Mockery::on(
                fn (array $context): bool => $context['previous_job_count'] === 0
                    && $context['source_language'] === 'auto'
                    && ! array_key_exists('youtube_video_id', $context)
                    && ! array_key_exists('youtube_url', $context)
                    && ! array_key_exists('transcript', $context),
            ));

        $this
            ->withExtensionAuth($installId, $user)
            ->postJson('/v1/subtitle-jobs', $this->validPayload(['youtubeVideoId' => 'second00001']))
            ->assertAccepted();

        Log::shouldHaveReceived('info')
            ->with('analytics.retention_generation_started', Mockery::on(
                fn (array $context): bool => $context['previous_job_count'] === 1
                    && ! array_key_exists('youtube_video_id', $context)
                    && ! array_key_exists('youtube_url', $context),
            ));
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

    private function installId(): string
    {
        return 'install_'.str_repeat('a', 32);
    }
}
