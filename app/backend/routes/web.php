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
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketingPageController::class, 'home'])
    ->name('marketing.home');
Route::redirect('/desktop', '/#install', 301)
    ->name('marketing.desktop');
Route::redirect('/extension', '/#install', 301)
    ->name('marketing.extension');
Route::get('/pricing', [MarketingPageController::class, 'pricing'])
    ->name('marketing.pricing');
Route::redirect('/languages', '/#languages', 301)
    ->name('marketing.languages');
Route::redirect('/how-it-works', '/#how', 301)
    ->name('marketing.how-it-works');
Route::redirect('/faq', '/#faq', 301)
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
