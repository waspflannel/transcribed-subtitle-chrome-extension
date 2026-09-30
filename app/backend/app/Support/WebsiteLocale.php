<?php

namespace App\Support;

final class WebsiteLocale
{
    public const REDIRECTS = [
        'how-to-use' => '/',
        'desktop' => '/#how-to-install',
        'extension' => '/#how-to-install',
        'how-it-works' => '/',
    ];

    public static function route(string $name, array $parameters = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        $marketing = strpos($name, 'marketing.');
        if ($marketing !== false) {
            $name = substr($name, $marketing);
            $alias = self::REDIRECTS[substr($name, strlen('marketing.'))] ?? null;
            $prefix = config('localization.prefixes')[$locale];
            $path = $alias === null
                ? route(($locale === 'en' ? '' : $locale.'.').$name, $parameters, false)
                : ($prefix === '' ? '' : '/'.$prefix).$alias;
            if ($alias !== null && $prefix !== '') {
                $path = rtrim(str_replace('/#', '#', $path), '/');
            }

            return rtrim(config('app.url'), '/').$path;
        }

        return route($name, $locale === 'en' ? $parameters : [...$parameters, 'lang' => $locale]);
    }

    public static function languageTag(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'zh-CN' ? 'zh-Hans' : $locale;
    }
}
