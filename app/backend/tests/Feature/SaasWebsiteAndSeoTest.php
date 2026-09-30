<?php

namespace Tests\Feature;

use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaasWebsiteAndSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_ignore_legacy_login_sessions_without_recreating_session_cookies(): void
    {
        config(['session.driver' => 'database']);
        $sessionId = str_repeat('a', 40);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => 42,
            'payload' => base64_encode(serialize(['login_web_'.sha1(SessionGuard::class) => 42])),
            'last_activity' => time(),
        ]);

        $this->withCookie(config('session.cookie'), $sessionId);
        foreach (['/', '/es'] as $path) {
            $this->get($path)->assertOk()
                ->assertCookieMissing(config('session.cookie'))
                ->assertCookieMissing('XSRF-TOKEN');
        }
    }

    public function test_instance_pages_keep_study_guides_and_remove_account_and_payment_surfaces(): void
    {
        foreach (['/'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('noindex,nofollow', false)
                ->assertDontSee('Stripe')
                ->assertDontSee('TypeSafe')
                ->assertDontSee('select Auto')
                ->assertDontSee('use Auto')
                ->assertDontSee('Auto chooses')
                ->assertDontSee('href="/login"', false)
                ->assertDontSee('href="/pricing"', false)
                ->assertDontSee('plan minutes')
                ->assertDontSee('hero-band')
                ->assertDontSee('site-footer')
                ->assertDontSee('/privacy')
                ->assertDontSee('/terms')
                ->assertDontSee('/support');
        }
        $this->get('/')->assertSee('Set up your instance')->assertSee('OpenAI')->assertSee('Cerebras')
            ->assertSee('Auto detect')->assertSee('Saved generations');
        foreach (['/login', '/register', '/forgot-password', '/pricing', '/dashboard', '/privacy', '/terms', '/support', '/languages', '/faq'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
    }

    public function test_site_stylesheets_remain_versioned(): void
    {
        foreach (['tokens', 'base', 'shell', 'ui', 'motion', 'responsive', 'guide'] as $stylesheet) {
            $this->get('/')->assertSee(
                asset('css/site/'.$stylesheet.'.css').'?v='.filemtime(public_path('css/site/'.$stylesheet.'.css')),
                false,
            );
        }
    }
}
