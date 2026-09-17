<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class WebsiteLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_nine_locales_render_public_and_account_pages_with_translated_copy(): void
    {
        $this->withHeader('Accept-Language', 'ja-JP')->withCookie('interface_locale', 'de');
        $canonicalUrls = [];
        foreach (array_keys(config('localization.locales')) as $locale) {
            $messages = json_decode(file_get_contents(base_path('../../packages/localization/website/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $prefix = config('localization.prefixes')[$locale];
            $tag = $locale === 'zh-CN' ? 'zh-Hans' : $locale;
            foreach (['/', '/pricing', '/how-to-use', '/privacy', '/terms', '/support'] as $path) {
                $localizedPath = $prefix === '' ? $path : '/'.$prefix.($path === '/' ? '' : $path);
                $canonical = config('app.url').$localizedPath;
                $canonicalUrls[] = $canonical;
                $response = $this->get($localizedPath.'?utm_source=launch')->assertOk()
                    ->assertSee('<html lang="'.$tag.'">', false)
                    ->assertSeeText($messages['Sign in'])
                    ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
                    ->assertSee('<link rel="alternate" hreflang="x-default" href="'.config('app.url').$path.'">', false)
                    ->assertDontSee(':slot')
                    ->assertHeader('Content-Language', $tag);
                $this->assertSame(1, substr_count($response->getContent(), 'rel="canonical"'));
                $this->assertSame(10, substr_count($response->getContent(), 'rel="alternate"'));
                foreach (config('localization.prefixes') as $alternateLocale => $alternatePrefix) {
                    $alternateTag = $alternateLocale === 'zh-CN' ? 'zh-Hans' : $alternateLocale;
                    $alternatePath = $alternatePrefix === '' ? $path : '/'.$alternatePrefix.($path === '/' ? '' : $path);
                    $response->assertSee('<link rel="alternate" hreflang="'.$alternateTag.'" href="'.config('app.url').$alternatePath.'">', false)
                        ->assertSee('<a href="'.config('app.url').$alternatePath.'" lang="'.$alternateTag.'"', false);
                }
            }
            foreach (['/login', '/register'] as $path) {
                $this->get($path.'?lang='.$locale)->assertOk()
                    ->assertSee('<html lang="'.$locale.'">', false)
                    ->assertSeeText($messages['Sign in'])
                    ->assertDontSee(':slot')
                    ->assertHeader('Content-Language', $locale);
            }
        }
        $sitemap = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertCount(54, $sitemap->url);
        $this->assertEqualsCanonicalizing($canonicalUrls, array_map(static fn ($entry): string => (string) $entry->loc, iterator_to_array($sitemap->url, false)));
        $this->actingAs(User::factory()->create())->get('/dashboard?lang=es')->assertOk()
            ->assertDontSee('Manage billing');
    }

    public function test_old_locale_queries_and_aliases_redirect_directly_to_the_canonical_page(): void
    {
        foreach (config('localization.prefixes') as $locale => $prefix) {
            $base = $prefix === '' ? '' : '/'.$prefix;
            foreach (['/' => ($base === '' ? '/' : $base), '/pricing' => $base.'/pricing'] as $old => $target) {
                $this->get($old.'?lang='.$locale)->assertMovedPermanently()->assertRedirect(config('app.url').$target);
            }
            foreach (['desktop' => '/how-to-use#how-to-install', 'extension' => '/how-to-use#how-to-install', 'languages' => ($base === '' ? '/' : '').'#languages', 'how-it-works' => '/how-to-use', 'faq' => ($base === '' ? '/' : '').'#faq'] as $alias => $destination) {
                $this->get('/'.$alias.'?lang='.$locale)->assertMovedPermanently()->assertRedirect(config('app.url').$base.$destination);
                $this->get($base.'/'.$alias)->assertMovedPermanently()->assertRedirect(config('app.url').$base.$destination);
            }
        }
        $this->get('/fr/pricing?lang=ja')->assertMovedPermanently()->assertRedirect(config('app.url').'/fr/pricing');
        $this->get('/es/pricing?lang[]=ja')->assertMovedPermanently()->assertRedirect(config('app.url').'/es/pricing');
        // Laravel's test URL helper strips trailing slashes before dispatch.
        foreach (['/es/', '/es/pricing/'] as $path) {
            $response = $this->app->handle(Request::create(config('app.url').$path));
            $this->assertSame(301, $response->getStatusCode());
            $this->assertSame(config('app.url').rtrim($path, '/'), $response->headers->get('Location'));
        }
        foreach (['/it/pricing', '/zh-tw', '/es/missing', '/es/dashboard', '/es/login'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_public_metadata_uses_configured_origin_and_analytics_excludes_query_values(): void
    {
        config(['app.url' => 'https://canonical.example']);
        Log::spy();
        $this->get('https://alternate.example/es/pricing?email=private@example.test')->assertOk()
            ->assertSee('<link rel="canonical" href="https://canonical.example/es/pricing">', false)
            ->assertDontSee('private@example.test');
        $this->get('/robots.txt')->assertSee('Sitemap: https://canonical.example/sitemap.xml', false);
        Log::shouldHaveReceived('info')->with('analytics.marketing_page_view', Mockery::on(
            fn (array $context): bool => $context['interface_locale'] === 'es'
                && $context['path'] === 'es/pricing'
                && ! str_contains(json_encode($context), 'private@example.test'),
        ));
    }

    public function test_explicit_locale_wins_over_cookie_and_browser_and_survives_logout(): void
    {
        $this->withHeader('Accept-Language', 'ja-JP,fr;q=0.8')->get('/login')->assertHeader('Content-Language', 'ja');
        $this->withCookie('interface_locale', 'es')->get('/login')->assertHeader('Content-Language', 'es');
        $this->get('/login?lang=de')->assertHeader('Content-Language', 'de')->assertCookie('interface_locale', 'de');
        $this->withCookie('interface_locale', 'de')->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');
        $this->get('/login')->assertHeader('Content-Language', 'de');
    }

    public function test_invalid_locale_is_ignored_and_switcher_keeps_registration_parameters(): void
    {
        $this->withHeader('Accept-Language', 'xx-ZZ,pt-PT;q=0.8')->get('/register?plan=starter&lang[]=unknown')
            ->assertOk()->assertHeader('Content-Language', 'pt-BR')
            ->assertSee('plan=starter&amp;lang=ja', false);
        $this->withHeader('Accept-Language', 'xx-ZZ')->get('/?lang=../../missing')->assertMovedPermanently()->assertRedirect(config('app.url').'/');
    }

    public function test_auth_validation_is_translated_and_web_locale_does_not_leak_into_api_requests(): void
    {
        $this->from('/register?lang=es')->post('/register?lang=es', [
            'name' => '', 'email' => 'invalid', 'password' => 'short',
        ])->assertSessionHasErrors(['name', 'email', 'password']);
        $messages = session('errors')->all();
        $this->assertStringNotContainsString('The ', implode(' ', $messages));
        $this->assertStringNotContainsString('validation.', implode(' ', $messages));
        $this->assertSame('en', app()->getLocale());
        $this->withHeader('X-Extension-Install-Id', 'install_localization_test')->getJson('/v1/extension-auth/account')
            ->assertUnauthorized()->assertJsonPath('error.message', 'A valid extension API token is required.');
    }

    public function test_every_catalog_preserves_all_placeholders_and_contains_no_injected_markup(): void
    {
        foreach (['website', 'extension'] as $surface) {
            $directory = base_path('../../packages/localization/'.$surface);
            $source = json_decode(file_get_contents($directory.'/en.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach (array_keys(config('localization.locales')) as $locale) {
                $messages = json_decode(file_get_contents($directory.'/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
                $this->assertEqualsCanonicalizing(array_keys($source), array_keys($messages), $surface.' '.$locale);
                foreach ($source as $key => $text) {
                    $this->assertNotSame('', trim($messages[$key]), $locale.' '.$key);
                    preg_match_all('/:slot\d+:|:[a-zA-Z][a-zA-Z0-9_]*|\{[a-zA-Z0-9_]+\}/', $text, $expected);
                    preg_match_all('/:slot\d+:|:[a-zA-Z][a-zA-Z0-9_]*|\{[a-zA-Z0-9_]+\}/', $messages[$key], $actual);
                    $this->assertEqualsCanonicalizing($expected[0], $actual[0], $locale.' '.$key);
                    if (! str_contains($text, '<')) {
                        $this->assertStringNotContainsString('<', $messages[$key]);
                    }
                }
            }
        }
    }
}
