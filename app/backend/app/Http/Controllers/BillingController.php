<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Analytics\FunnelAnalytics;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\StripeClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BillingController extends Controller
{
    public function checkout(
        Request $request,
        string $planCode,
        BillingPlanCatalog $plans,
        BillingEntitlementService $billing,
        StripeClient $stripe,
        FunnelAnalytics $analytics,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $plan = $plans->plan($planCode);

        if ($plan === null) {
            abort(404);
        }

        if ($billing->subscriptionRequiresPortal($user)) {
            return redirect()
                ->route('dashboard')
                ->with('billing_status', 'Your existing subscription is managed in Stripe. Use Manage billing to change it.');
        }

        $analytics->checkoutStarted($user, $plan);

        $request->session()->forget('checkout_plan');

        try {
            $session = $stripe->createCheckoutSession(
                user: $user,
                plan: $plan,
                successUrl: route('dashboard', ['billing' => 'success']),
                cancelUrl: route('dashboard', ['billing' => 'cancelled']),
            );
        } catch (HttpClientException|RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('dashboard')
                ->with('billing_error', 'Billing is temporarily unavailable. Try again shortly.');
        }

        return redirect()->away($session['url']);
    }

    public function portal(Request $request, StripeClient $stripe): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $session = $stripe->createBillingPortalSession($user, route('dashboard'));
        } catch (HttpClientException|RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('dashboard')
                ->with('billing_error', 'Billing is temporarily unavailable. Try again shortly.');
        }

        return redirect()->away($session['url']);
    }
}
