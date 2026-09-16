<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $routes = [
            ['marketing.home', '1.0'],
            ['marketing.pricing', '0.9'],
            ['marketing.how-to-use', '0.8'],
            ['marketing.support', '0.6'],
            ['marketing.privacy', '0.4'],
            ['marketing.terms', '0.4'],
        ];

        $urls = array_map(
            static fn (array $route): string => implode("\n", [
                '    <url>',
                '        <loc>'.e(route($route[0])).'</loc>',
                '        <changefreq>weekly</changefreq>',
                '        <priority>'.$route[1].'</priority>',
                '    </url>',
            ]),
            $routes,
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
