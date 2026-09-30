<?php

namespace App\Http\Middleware;

use App\Support\WebsiteLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetWebsiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = array_keys(config('localization.locales'));
        $selected = $request->query('lang');
        $saved = $request->cookie('interface_locale');
        $locale = in_array($selected, $locales, true) ? $selected : $saved;
        $publicPage = $request->routeIs('marketing.*', '*.marketing.*');

        if ($publicPage) {
            $name = $request->route()->getName();
            $routeLocale = str_starts_with($name, 'marketing.') ? 'en' : explode('.', $name)[0];
            $locale = $routeLocale === 'en' && in_array($selected, $locales, true) ? $selected : $routeLocale;
            $canonical = WebsiteLocale::route($name, locale: $locale);

            if ($request->query->has('lang') || $request->getPathInfo() !== parse_url($canonical, PHP_URL_PATH)) {
                return redirect($canonical, 301);
            }
        }

        if (! in_array($locale, $locales, true)) {
            $locale = 'en';
            foreach ($request->getLanguages() as $language) {
                $language = strtolower(str_replace('_', '-', $language));
                foreach ($locales as $supported) {
                    if (explode('-', $language)[0] === strtolower(explode('-', $supported)[0])) {
                        $locale = $supported;
                        break 2;
                    }
                }
            }
        }

        $originalLocale = app()->getLocale();
        app()->setLocale($locale);
        try {
            $response = $next($request);
        } finally {
            app()->setLocale($originalLocale);
        }
        $response->headers->set('Content-Language', $publicPage ? WebsiteLocale::languageTag($locale) : $locale);
        $response->setVary($publicPage ? ['Cookie'] : ['Accept-Language', 'Cookie'], false);

        if ($publicPage || in_array($selected, $locales, true)) {
            $response->headers->setCookie(cookie('interface_locale', $locale, 60 * 24 * 365));
        }

        return $response;
    }
}
