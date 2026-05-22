<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\StripeWebhookController;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', fn (
    Request $request,
    BillingEntitlementService $billing,
    BillingPlanCatalog $plans,
): View => view('dashboard', [
    'user' => $request->user(),
    'account' => $billing->accountSummary($request->user()),
    'plans' => $plans->publicPlans(),
]))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::post('/billing/checkout/{planCode}', [BillingController::class, 'checkout'])
    ->middleware(['auth', 'verified'])
    ->name('billing.checkout');

Route::post('/billing/portal', [BillingController::class, 'portal'])
    ->middleware(['auth', 'verified'])
    ->name('billing.portal');

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook');
