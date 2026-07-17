<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\StripeClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountController extends Controller
{
    public function destroy(
        Request $request,
        BillingEntitlementService $billing,
        StripeClient $stripe,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $request->validateWithBag('deleteAccount', [
            'password' => ['required', 'current_password'],
        ]);

        if ($this->hasCancellableSubscription($user, $billing)) {
            try {
                $stripe->cancelSubscription((string) $user->stripe_subscription_id);
            } catch (HttpClientException|RuntimeException $exception) {
                report($exception);

                return redirect()
                    ->route('dashboard')
                    ->with('billing_error', 'We could not cancel your subscription, so your account was not deleted. Try again shortly.');
            }
        }

        $user->subtitleJobs()
            ->select(['id', 'run_id'])
            ->chunkById(200, function (Collection $jobs): void {
                foreach ($jobs as $job) {
                    SubtitleAudioWorkspace::delete($job->run_id);
                }
            });

        Auth::logout();

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }

            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'Your account and all of its data were deleted.');
    }

    private function hasCancellableSubscription(User $user, BillingEntitlementService $billing): bool
    {
        return $billing->subscriptionRequiresPortal($user)
            && is_string($user->stripe_subscription_id)
            && $user->stripe_subscription_id !== '';
    }
}
