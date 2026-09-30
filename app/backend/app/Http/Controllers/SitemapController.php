<?php

namespace App\Http\Controllers;

use App\Support\WebsiteLocale;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $variants = [];
        foreach (array_keys(config('localization.locales')) as $locale) {
            $variants[] = WebsiteLocale::route('marketing.home', locale: $locale);
        }

        $urls = array_map(
            static fn (string $url): string => implode("\n", [
                '    <url>',
                '        <loc>'.e($url).'</loc>',
                '    </url>',
            ]),
            $variants,
        );

        $body = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            ...$urls,
            '</urlset>',
            '',
        ]);

        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
