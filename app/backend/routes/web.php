<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WebSubtitleJobController;
use App\Http\Middleware\AuthenticateWebSession;
use App\Support\WebsiteLocale;
use Illuminate\Support\Facades\Route;

foreach (config('localization.prefixes') as $locale => $prefix) {
    Route::prefix($prefix)->name($locale === 'en' ? '' : $locale.'.')->group(function () use ($prefix): void {
        Route::get('/', [MarketingPageController::class, 'home'])->name('marketing.home');
        Route::get('/pricing', [MarketingPageController::class, 'pricing'])->name('marketing.pricing');
        Route::get('/how-to-use', [MarketingPageController::class, 'howToUse'])->name('marketing.how-to-use');
        Route::get('/privacy', [MarketingPageController::class, 'privacy'])->name('marketing.privacy');
        Route::get('/terms', [MarketingPageController::class, 'terms'])->name('marketing.terms');
        Route::get('/support', [MarketingPageController::class, 'support'])->name('marketing.support');

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

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', AuthenticateWebSession::class])
    ->name('dashboard');
Route::get('/dashboard/jobs/{jobId}', [WebSubtitleJobController::class, 'show'])
    ->middleware(['auth', AuthenticateWebSession::class])
    ->name('dashboard.jobs.show');
Route::delete('/dashboard/jobs/{jobId}', [WebSubtitleJobController::class, 'destroy'])
    ->middleware(['auth', AuthenticateWebSession::class])
    ->name('dashboard.jobs.destroy');
Route::delete('/dashboard/jobs', [WebSubtitleJobController::class, 'clearAll'])
    ->middleware(['auth', AuthenticateWebSession::class])
    ->name('dashboard.jobs.clear');

Route::post('/billing/checkout/{planCode}', [BillingController::class, 'checkout'])
    ->middleware(['auth', AuthenticateWebSession::class])
    ->name('billing.checkout');

Route::post('/billing/portal', [BillingController::class, 'portal'])
    ->middleware(['auth', AuthenticateWebSession::class])
    ->name('billing.portal');

Route::delete('/account', [AccountController::class, 'destroy'])
    ->middleware(['auth', AuthenticateWebSession::class, 'throttle:6,1'])
    ->name('account.destroy');

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook');
