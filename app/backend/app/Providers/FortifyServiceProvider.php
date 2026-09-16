<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Services\Billing\BillingPlanCatalog;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $resetLinkResponse = fn (): Responsable => new class implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
        {
            public function toResponse($request)
            {
                $message = __('If an account matches that email, a password reset link will be sent.');

                return $request->wantsJson()
                    ? response()->json(['message' => $message])
                    : back()->with('status', $message);
            }
        };
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, $resetLinkResponse);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, $resetLinkResponse);

        $this->app->singleton(RegisterResponse::class, fn (): Responsable => new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                $plan = app(BillingPlanCatalog::class)->plan((string) $request->input('plan'));

                if ($plan !== null) {
                    $request->session()->put('checkout_plan', $plan['code']);
                }

                return redirect()->route('dashboard');
            }
        });

        $this->app->singleton(LogoutResponse::class, fn (): Responsable => new class implements LogoutResponse
        {
            public function toResponse($request)
            {
                return $request->wantsJson()
                    ? response()->noContent()
                    : redirect()->route('login');
            }
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            $request = request();

            if ($event->guard === config('fortify.guard', 'web') && $request->hasSession()) {
                $request->session()->put('password_hash_'.$event->guard, Auth::guard($event->guard)->hashPasswordForCookie($event->user->getAuthPassword()));
            }
        });

        foreach (['registration', 'password-reset-mail'] as $name) {
            RateLimiter::for($name, fn (Request $request): array => [
                Limit::perHour(max(1, (int) config("fortify.abuse_limits.{$name}.ip_per_hour")))->by('ip:'.$request->ip()),
                Limit::perHour(max(1, (int) config("fortify.abuse_limits.{$name}.global_per_hour")))->by('global'),
            ]);
        }

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn (Request $request) => view('auth.register', [
            'selectedPlan' => app(BillingPlanCatalog::class)->plan((string) $request->query('plan')),
        ]));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', [
            'email' => $request->query('email'),
            'token' => $request->route('token'),
        ]));
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
