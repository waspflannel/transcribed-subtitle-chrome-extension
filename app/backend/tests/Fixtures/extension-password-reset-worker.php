<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\NewAccessToken;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $directory, $userId, $label, $mode] = $argv;
$connection = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);

if ($connection['host'] !== '127.0.0.1'
    || ! preg_match('/^subtitle_review_test_[a-z0-9]+$/', $connection['database'])
    || ! preg_match('/^password_reset_test_[a-f0-9]+$/', $connection['search_path'])) {
    throw new RuntimeException('Worker requires an isolated test database and schema.');
}

$connection['application_name'] = $connection['search_path'].'_'.$label;
config(['database.connections.password_reset_test' => $connection, 'database.default' => 'password_reset_test']);
Http::preventStrayRequests();
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

if ($mode === 'login-paused') {
    $app->instance(ExtensionTokenIssuer::class, new class($pause) extends ExtensionTokenIssuer
    {
        public function __construct(private Closure $pause) {}

        public function issue(User $user, string $installId): NewAccessToken
        {
            ($this->pause)();

            return parent::issue($user, $installId);
        }
    });
}

if ($mode === 'reset-paused') {
    User::updated(function (User $updated) use ($userId, $pause): void {
        if ((string) $updated->getKey() === $userId && $updated->wasChanged('password')) {
            $pause();
        }
    });
}

if (str_starts_with($mode, 'reset')) {
    app(ResetUserPassword::class)->reset($user, [
        'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
    ]);
    echo "password-reset\n";
} else {
    $request = Request::create('/v1/extension-auth/login', 'POST', [
        'email' => $user->email, 'password' => 'old-password-123',
    ], server: [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_EXTENSION_INSTALL_ID' => 'install_0123456789abcdef0123456789abcdef',
    ]);
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($directory.'/login-result.json', json_encode([
        'status' => $response->getStatusCode(),
        'token' => $body['token']['plainTextToken'] ?? null,
        'error' => $body['error']['code'] ?? null,
    ], JSON_THROW_ON_ERROR));
    $kernel->terminate($request, $response);
    echo 'login-status:'.$response->getStatusCode()."\n";
}
