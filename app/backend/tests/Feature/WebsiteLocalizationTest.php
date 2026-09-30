<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class WebsiteLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_nine_locales_render_instance_pages_with_translated_copy(): void
    {
        $this->withHeader('Accept-Language', 'ja-JP')->withCookie('interface_locale', 'de');
        $canonicalUrls = [];
        foreach (array_keys(config('localization.locales')) as $locale) {
            $messages = json_decode(file_get_contents(base_path('../../packages/localization/website/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $prefix = config('localization.prefixes')[$locale];
            $tag = $locale === 'zh-CN' ? 'zh-Hans' : $locale;
            foreach (['/'] as $path) {
                $localizedPath = $prefix === '' ? $path : '/'.$prefix.($path === '/' ? '' : $path);
                $canonical = config('app.url').$localizedPath;
                $canonicalUrls[] = $canonical;
                $response = $this->get($localizedPath.'?utm_source=launch')->assertOk()
                    ->assertSee('<html lang="'.$tag.'">', false)
                    ->assertSeeText($messages['How To Use'])
                    ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
                    ->assertSee('<link rel="alternate" hreflang="x-default" href="'.config('app.url').$path.'">', false)
                    ->assertDontSee('TypeSafe')
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
        }
        $sitemap = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertCount(9, $sitemap->url);
        $this->assertEqualsCanonicalizing($canonicalUrls, array_map(static fn ($entry): string => (string) $entry->loc, iterator_to_array($sitemap->url, false)));
    }

    public function test_old_locale_queries_and_aliases_redirect_directly_to_the_canonical_page(): void
    {
        foreach (config('localization.prefixes') as $locale => $prefix) {
            $base = $prefix === '' ? '' : '/'.$prefix;
            foreach (['/' => ($base === '' ? '/' : $base), '/how-to-use' => ($base === '' ? '/' : $base)] as $old => $target) {
                $this->get($old.'?lang='.$locale)->assertMovedPermanently()->assertRedirect(config('app.url').$target);
            }
            foreach (['desktop' => ($base === '' ? '/' : '').'#how-to-install', 'extension' => ($base === '' ? '/' : '').'#how-to-install', 'how-it-works' => ($base === '' ? '/' : ''), 'how-to-use' => ($base === '' ? '/' : '')] as $alias => $destination) {
                $this->get('/'.$alias.'?lang='.$locale)->assertMovedPermanently()->assertRedirect(config('app.url').$base.$destination);
                $this->get($base.'/'.$alias)->assertMovedPermanently()->assertRedirect(config('app.url').$base.$destination);
            }
        }
        $this->get('/fr/how-to-use?lang=ja')->assertMovedPermanently()->assertRedirect(config('app.url').'/fr');
        $this->get('/es/how-to-use?lang[]=ja')->assertMovedPermanently()->assertRedirect(config('app.url').'/es');
        // Laravel's test URL helper strips trailing slashes before dispatch.
        foreach (['/es/', '/es/how-to-use/'] as $path) {
            $response = $this->app->handle(Request::create(config('app.url').$path));
            $this->assertSame(301, $response->getStatusCode());
            $this->assertSame(config('app.url').'/es', $response->headers->get('Location'));
        }
        foreach (['/it/how-to-use', '/zh-tw', '/es/missing', '/es/dashboard', '/es/login', '/es/privacy', '/es/terms', '/es/support', '/es/languages', '/es/faq'] as $path) {
            $this->get($path)->assertNotFound();
        }
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
