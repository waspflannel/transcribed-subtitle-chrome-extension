<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Services\Billing\BillingPlanCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RegisterResponse::class, fn (): Responsable => new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                $plan = app(BillingPlanCatalog::class)->plan((string) $request->input('plan'));

                if ($plan !== null) {
                    $request->session()->put('checkout_plan', $plan['code']);
                }

                return redirect()->route('verification.notice');
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

        $this->app->singleton(VerifyEmailResponse::class, fn (): Responsable => new class implements VerifyEmailResponse
        {
            public function toResponse($request)
            {
                return $request->wantsJson()
                    ? response()->noContent()
                    : redirect()->route('dashboard');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn (Request $request) => view('auth.register', [
            'selectedPlan' => app(BillingPlanCatalog::class)->plan((string) $request->query('plan')),
        ]));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', [
            'email' => $request->query('email'),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
