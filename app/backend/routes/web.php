<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WebSubtitleJobController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingPageController::class, 'home'])
    ->name('marketing.home');
Route::redirect('/desktop', '/extension', 301)
    ->name('marketing.desktop');
Route::get('/extension', [MarketingPageController::class, 'extension'])
    ->name('marketing.extension');
Route::get('/pricing', [MarketingPageController::class, 'pricing'])
    ->name('marketing.pricing');
Route::get('/languages', [MarketingPageController::class, 'languages'])
    ->name('marketing.languages');
Route::get('/how-it-works', [MarketingPageController::class, 'howItWorks'])
    ->name('marketing.how-it-works');
Route::get('/faq', [MarketingPageController::class, 'faq'])
    ->name('marketing.faq');
Route::get('/privacy', [MarketingPageController::class, 'privacy'])
    ->name('marketing.privacy');
Route::get('/terms', [MarketingPageController::class, 'terms'])
    ->name('marketing.terms');
Route::get('/support', [MarketingPageController::class, 'support'])
    ->name('marketing.support');

Route::get('/robots.txt', RobotsController::class)
    ->name('robots');
Route::get('/sitemap.xml', SitemapController::class)
    ->name('sitemap');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
Route::get('/dashboard/jobs/{jobId}', [WebSubtitleJobController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.jobs.show');

Route::post('/billing/checkout/{planCode}', [BillingController::class, 'checkout'])
    ->middleware(['auth', 'verified'])
    ->name('billing.checkout');

Route::post('/billing/portal', [BillingController::class, 'portal'])
    ->middleware(['auth', 'verified'])
    ->name('billing.portal');

Route::post('/billing/testing-plan', [BillingController::class, 'testingPlan'])
    ->middleware(['auth', 'verified'])
    ->name('billing.testing-plan');

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook');
