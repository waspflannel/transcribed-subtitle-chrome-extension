<?php

use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Support\WebsiteLocale;
use Illuminate\Support\Facades\Route;

foreach (config('localization.prefixes') as $locale => $prefix) {
    Route::prefix($prefix)->name($locale === 'en' ? '' : $locale.'.')->group(function () use ($prefix): void {
        Route::get('/', [MarketingPageController::class, 'home'])->name('marketing.home');

        foreach (WebsiteLocale::REDIRECTS as $alias => $destination) {
            Route::redirect('/'.$alias, ($prefix === '' ? '' : '/'.$prefix).$destination, 301)
                ->name('marketing.'.$alias);
        }
    });
}

Route::get('/robots.txt', RobotsController::class)
    ->name('robots');
Route::get('/sitemap.xml', SitemapController::class)
    ->name('sitemap');
