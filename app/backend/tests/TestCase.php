<?php

namespace Tests;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        require __DIR__.'/bootstrap.php';
        $app = require __DIR__.'/../bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $expected = [
                'app.env' => 'testing',
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'cache.default' => 'array',
                'subtitles.tiers.concurrency_cache_store' => 'array',
                'session.driver' => 'array',
                'mail.default' => 'array',
                'queue.default' => 'sync',
                'subtitles.queue.connection' => 'sync',
                'filesystems.default' => 'local',
                'filesystems.disks.local.root' => $app->storagePath('app/private'),
            ];
            foreach ($expected as $key => $value) {
                if ($app['config']->get($key) !== $value) {
                    throw new \RuntimeException('Unsafe test configuration: '.$key);
                }
            }
            if ($app->storagePath() !== SUBTITLE_TEST_STORAGE || $app['config']->get('database.connections.sqlite.url')) {
                throw new \RuntimeException('Unsafe test database URL.');
            }
        });
        $app->make(Kernel::class)->bootstrap();
        Http::preventStrayRequests();

        return $app;
    }

    /**
     * @var array<string, string>
     */
    private array $extensionAuthTokens = [];

    protected function withExtensionAuth(string $installId, ?User $user = null): static
    {
        $this->app['auth']->forgetGuards();

        $cacheKey = $installId.':'.($user?->getKey() ?? 'default');

        if (! isset($this->extensionAuthTokens[$cacheKey])) {
            $user ??= SubtitleJob::query()
                ->where('install_id', $installId)
                ->whereNotNull('user_id')
                ->latest('id')
                ->first()
                ?->user
                ?? User::factory()->create();
            $this->ensureActiveBilling($user);
            $issuedToken = app(ExtensionTokenIssuer::class)->issue($user, $installId);
            $this->extensionAuthTokens[$cacheKey] = $issuedToken->plainTextToken;
        }

        return $this
            ->withHeader('X-Extension-Install-Id', $installId)
            ->withHeader('Authorization', 'Bearer '.$this->extensionAuthTokens[$cacheKey]);
    }

    private function ensureActiveBilling(User $user): void
    {
        $planCode = is_string($user->billing_plan_code) && $user->billing_plan_code !== ''
            ? $user->billing_plan_code
            : 'base';
        $periodStart = now()->startOfMonth()->toImmutable();
        $periodEnd = now()->addMonthNoOverflow()->startOfMonth()->toImmutable();

        $user->forceFill([
            'stripe_customer_id' => $user->stripe_customer_id ?? 'cus_test_'.$user->id,
            'stripe_subscription_id' => $user->stripe_subscription_id ?? 'sub_test_'.$user->id,
            'stripe_subscription_item_id' => $user->stripe_subscription_item_id ?? 'si_test_'.$user->id,
            'billing_plan_code' => $planCode,
            'billing_subscription_status' => 'active',
            'billing_current_period_start' => $periodStart,
            'billing_current_period_end' => $periodEnd,
            'billing_cancel_at_period_end' => false,
        ])->save();

        app(UsageLedger::class)->ensureMonthlyGrant(
            $user->refresh(),
            app(BillingPlanCatalog::class)->requirePlan($planCode),
            $periodStart,
            $periodEnd,
        );
    }
}
