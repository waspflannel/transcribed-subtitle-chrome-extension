<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\Auth\ExtensionTokenIssuer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExtensionPasswordResetConcurrencyTest extends TestCase
{
    private const INSTALL_ID = 'install_0123456789abcdef0123456789abcdef';

    private string $schema;

    private string $directory;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SUBTITLE_DISPOSABLE_PG_PORT') === false) {
            $this->markTestSkipped('Requires an explicitly configured disposable PostgreSQL container.');
        }

        $database = (string) getenv('SUBTITLE_DISPOSABLE_PG_DATABASE');
        $port = (int) getenv('SUBTITLE_DISPOSABLE_PG_PORT');
        $this->assertMatchesRegularExpression('/^subtitle_review_test_[a-z0-9]+$/', $database);
        $this->assertGreaterThan(1024, $port);
        $this->schema = 'password_reset_test_'.bin2hex(random_bytes(8));
        $this->directory = SUBTITLE_TEST_STORAGE.'/'.$this->schema;
        mkdir($this->directory, 0700);
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port,
            'database' => $database, 'username' => (string) getenv('SUBTITLE_DISPOSABLE_PG_USERNAME'),
            'password' => (string) getenv('SUBTITLE_DISPOSABLE_PG_PASSWORD'),
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema,
            'sslmode' => 'disable', 'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
        config(['database.connections.password_reset_test' => $connection, 'database.default' => 'password_reset_test']);
        DB::purge('password_reset_test');
        DB::statement('CREATE SCHEMA '.$this->schema);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'password_reset_test', '--force' => true]));
        file_put_contents($this->directory.'/connection.json', json_encode($connection, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }

        if (isset($this->schema)) {
            DB::statement('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE');
            DB::purge('password_reset_test');
        }

        parent::tearDown();
    }

    #[TestWith(['login'])]
    #[TestWith(['reset'])]
    public function test_old_password_cannot_leave_a_valid_token_after_concurrent_reset(string $firstAction): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        $existingToken = app(ExtensionTokenIssuer::class)->issue($user, self::INSTALL_ID)->plainTextToken;
        $secondAction = $firstAction === 'login' ? 'reset' : 'login';
        $first = $this->startWorker($user, $firstAction, $firstAction.'-paused');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/'.$firstAction.'-paused'));
        $second = $this->startWorker($user, $secondAction, $secondAction);
        $this->waitUntil(fn (): bool => DB::table('pg_stat_activity')
            ->where('application_name', $this->schema.'_'.$secondAction)
            ->where('wait_event_type', 'Lock')
            ->whereIn('wait_event', ['transactionid', 'tuple'])
            ->where('query', 'like', '%"users"%')->exists());
        $this->assertTrue($first->isRunning());
        $this->assertTrue($second->isRunning());
        touch($this->directory.'/'.$firstAction.'-release');
        $this->assertWorkerSucceeded($first);
        $this->assertWorkerSucceeded($second);

        $login = json_decode(file_get_contents($this->directory.'/login-result.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($firstAction === 'login' ? 200 : 422, $login['status']);
        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($existingToken));
        if ($firstAction === 'login') {
            $this->assertNotEmpty($login['token']);
            $this->assertNull(PersonalAccessToken::findToken($login['token']));
        } else {
            $this->assertSame('invalid_credentials', $login['error']);
            $this->assertNull($login['token']);
        }

        $headers = ['X-Extension-Install-Id' => self::INSTALL_ID];
        $this->postJson('/v1/extension-auth/login', ['email' => $user->email, 'password' => 'old-password-123'], $headers)
            ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_credentials');
        $newLogin = $this->postJson('/v1/extension-auth/login', ['email' => $user->email, 'password' => 'new-password-456'], $headers)
            ->assertOk();
        $newToken = $newLogin->json('token.plainTextToken');
        $this->assertNotNull(PersonalAccessToken::findToken($newToken));
        $this->app['auth']->forgetGuards();
        $this->getJson('/v1/extension-auth/account', [...$headers, 'Authorization' => 'Bearer '.$newToken])->assertOk();
    }

    private function startWorker(User $user, string $label, string $mode): Process
    {
        $worker = new Process([
            PHP_BINARY, base_path('tests/Fixtures/extension-password-reset-worker.php'),
            $this->directory, (string) $user->id, $label, $mode,
        ], base_path(), timeout: 20);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function waitUntil(callable $condition): void
    {
        $deadline = microtime(true) + 10;
        do {
            if ($condition()) {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('Concurrency barrier timed out. '.implode(' ', array_map(fn (Process $worker): string => $worker->getOutput().$worker->getErrorOutput(), $this->workers)));
    }

    private function assertWorkerSucceeded(Process $worker): void
    {
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
    }
}
