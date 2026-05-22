<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\StripeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BillingController extends Controller
{
    public function checkout(
        Request $request,
        string $planCode,
        BillingPlanCatalog $plans,
        StripeClient $stripe,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $plan = $plans->plan($planCode);

        if ($plan === null) {
            abort(404);
        }

        try {
            $session = $stripe->createCheckoutSession(
                user: $user,
                plan: $plan,
                successUrl: route('dashboard', ['billing' => 'success']),
                cancelUrl: route('dashboard', ['billing' => 'cancelled']),
            );
        } catch (RuntimeException $exception) {
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
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('dashboard')
                ->with('billing_error', 'Billing is temporarily unavailable. Try again shortly.');
        }

        return redirect()->away($session['url']);
    }
}
