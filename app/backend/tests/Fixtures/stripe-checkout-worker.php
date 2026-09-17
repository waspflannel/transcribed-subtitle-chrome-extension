<?php

use App\Exceptions\StripeCheckoutPendingException;
use App\Http\Controllers\AccountController;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\StripeClient;
use App\Services\Billing\StripeWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $directory, $userId, $label, $plan, $mode] = $argv;
$connection = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);

if ($connection['host'] !== '127.0.0.1'
    || ! preg_match('/^subtitle_review_test_[a-z0-9]+$/', $connection['database'])
    || ! preg_match('/^checkout_test_[a-f0-9]+$/', $connection['search_path'])) {
    throw new RuntimeException('Worker requires an isolated test database and schema.');
}

$connection['application_name'] = $connection['search_path'].'_'.$label;
config([
    'database.connections.checkout_test' => $connection, 'database.default' => 'checkout_test',
    'billing.stripe.secret' => 'sk_test_fake_only',
    'billing.plans.base.stripe_price_id' => 'price_base', 'billing.plans.plus.stripe_price_id' => 'price_plus',
]);
$user = User::query()->findOrFail($userId);
$pause = function () use ($directory, $label): void {
    touch($directory.'/'.$label.'-paused');
    $deadline = microtime(true) + 10;
    while (! is_file($directory.'/'.$label.'-release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(20_000);
    }
};
$record = fn (string $event) => file_put_contents($directory.'/provider-events', $event."\n", FILE_APPEND | LOCK_EX);
$subscription = [
    'id' => 'sub_paid', 'customer' => 'cus_concurrency', 'status' => 'active',
    'metadata' => ['user_id' => (string) $userId, 'plan_code' => 'base', 'checkout_intent_id' => 'a409960d-5b27-4458-9d7e-f65305d2d1cb'],
    'items' => ['data' => [[
        'id' => 'si_paid', 'price' => ['id' => 'price_base'],
        'current_period_start' => now()->startOfMonth()->timestamp,
        'current_period_end' => now()->addMonthNoOverflow()->startOfMonth()->timestamp,
    ]]],
];
Http::preventStrayRequests();
Http::fake(function ($request) use ($mode, $pause, $record, $label, $subscription) {
    if ($request->url() === 'https://api.stripe.com/v1/subscriptions/sub_paid') {
        return Http::response($subscription);
    }
    if (str_ends_with($request->url(), '/cs_a/expire')) {
        if ($mode === 'complete-expire') {
            $record('complete:cs_a');
            $pause();

            return Http::response(['error' => ['code' => 'checkout_session_not_open']], 400);
        }
        $record('expire:cs_a');

        return Http::response(['id' => 'cs_a', 'status' => 'expired']);
    }
    if (str_ends_with($request->url(), '/cs_a')) {
        return Http::response(['id' => 'cs_a', 'status' => 'complete', 'subscription' => 'sub_paid']);
    }
    if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
        $record('create:cs_'.$label);
        if ($mode === 'pause-create') {
            $pause();
        }

        return Http::response(['id' => 'cs_'.$label, 'url' => 'https://checkout.stripe.test/'.$label]);
    }

    throw new RuntimeException('Unexpected fake Stripe request.');
});

if ($mode === 'delete-account') {
    $request = Request::create('/account', 'DELETE', ['password' => 'password']);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    Auth::guard('web')->setUser($user);
    $response = app(AccountController::class)->destroy($request, app(BillingEntitlementService::class), app(StripeClient::class));

    if (User::query()->find($userId) !== null || ! str_ends_with($response->getTargetUrl(), '/login')) {
        throw new RuntimeException('Account deletion did not complete.');
    }
    echo "account-deleted\n";
} elseif ($mode === 'webhook') {
    $event = [
        'id' => 'evt_complete', 'type' => 'checkout.session.completed', 'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'cs_a', 'customer' => 'cus_concurrency', 'subscription' => 'sub_paid',
            'metadata' => $subscription['metadata'],
        ]],
    ];
    app(StripeWebhookService::class)->handle($event, json_encode($event, JSON_THROW_ON_ERROR));
    echo "webhook-reconciled\n";
} else {
    try {
        app(StripeClient::class)->createCheckoutSession($user, app(BillingPlanCatalog::class)->requirePlan($plan), '/success', '/cancel');
        if ($mode === 'complete-expire') {
            throw new LogicException('Unexpected second payable checkout.');
        }
        echo "checkout-created\n";
    } catch (RuntimeException $exception) {
        if ($mode !== 'complete-expire' || ! ($exception instanceof StripeCheckoutPendingException)) {
            throw $exception;
        }
        echo "replacement-rejected\n";
    }
}
