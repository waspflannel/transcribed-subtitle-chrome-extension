<?php

namespace Tests\Integration;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StripeCheckoutConcurrencyTest extends TestCase
{
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
        $this->schema = 'checkout_test_'.bin2hex(random_bytes(8));
        $this->directory = SUBTITLE_TEST_STORAGE.'/'.$this->schema;
        mkdir($this->directory, 0700);
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port,
            'database' => $database, 'username' => (string) getenv('SUBTITLE_DISPOSABLE_PG_USERNAME'),
            'password' => (string) getenv('SUBTITLE_DISPOSABLE_PG_PASSWORD'),
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema,
            'sslmode' => 'disable', 'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
        config(['database.connections.checkout_test' => $connection, 'database.default' => 'checkout_test']);
        DB::purge('checkout_test');
        DB::statement('CREATE SCHEMA '.$this->schema);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'checkout_test', '--force' => true]));
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
            DB::purge('checkout_test');
        }

        parent::tearDown();
    }

    public function test_competing_plan_switch_waits_for_creation_then_expires_the_only_old_session(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_concurrency']);
        $first = $this->startWorker($user, 'a', 'base', 'pause-create');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/a-paused'));
        $second = $this->startWorker($user, 'b', 'plus', 'normal');
        $this->waitForDatabaseLock('b');
        $this->assertTrue($first->isRunning());
        $this->assertTrue($second->isRunning());
        touch($this->directory.'/a-release');
        $this->assertWorkerSucceeded($first);
        $this->assertWorkerSucceeded($second);

        $this->assertSame(['create:cs_a', 'expire:cs_a', 'create:cs_b'], file($this->directory.'/provider-events', FILE_IGNORE_NEW_LINES));
        $this->assertSame('cs_b', $user->fresh()->stripe_checkout_session_id);
        $this->assertSame('plus', $user->fresh()->stripe_checkout_plan_code);
    }

    public function test_completion_during_expiration_blocks_new_checkout_and_webhook_reconciles_after_lock_release(): void
    {
        $user = User::factory()->create([
            'stripe_customer_id' => 'cus_concurrency', 'stripe_checkout_intent_id' => 'a409960d-5b27-4458-9d7e-f65305d2d1cb',
            'stripe_checkout_plan_code' => 'base', 'stripe_checkout_session_id' => 'cs_a',
            'stripe_checkout_session_url' => 'https://checkout.stripe.test/a', 'stripe_checkout_expires_at' => now()->addMinutes(20),
        ]);
        $switch = $this->startWorker($user, 'b', 'plus', 'complete-expire');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/b-paused'));
        $webhook = $this->startWorker($user, 'c', 'base', 'webhook');
        $this->waitForDatabaseLock('c');
        touch($this->directory.'/b-release');
        $this->assertWorkerSucceeded($switch);
        $this->assertStringContainsString('replacement-rejected', $switch->getOutput());
        $this->assertWorkerSucceeded($webhook);

        $this->assertSame(['complete:cs_a'], file($this->directory.'/provider-events', FILE_IGNORE_NEW_LINES));
        $this->assertSame('sub_paid', $user->fresh()->stripe_subscription_id);
        $this->assertSame('active', $user->fresh()->billing_subscription_status);
        $this->assertNull($user->fresh()->stripe_checkout_intent_id);
        $this->assertSame(1, DB::table('billing_usage_events')->where('event_type', 'monthly_grant')->count());
    }

    public function test_account_deletion_waits_for_concurrent_checkout_creation_and_expires_it_before_deleting(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_concurrency']);
        $creation = $this->startWorker($user, 'a', 'base', 'pause-create');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/a-paused'));
        $deletion = $this->startWorker($user, 'd', 'base', 'delete-account');
        $this->waitForDatabaseLock('d');
        touch($this->directory.'/a-release');
        $this->assertWorkerSucceeded($creation);
        $this->assertWorkerSucceeded($deletion);

        $this->assertSame(['create:cs_a', 'expire:cs_a'], file($this->directory.'/provider-events', FILE_IGNORE_NEW_LINES));
        $this->assertStringContainsString('account-deleted', $deletion->getOutput());
        $this->assertModelMissing($user);
    }

    private function startWorker(User $user, string $label, string $plan, string $mode): Process
    {
        $worker = new Process([
            PHP_BINARY, base_path('tests/Fixtures/stripe-checkout-worker.php'),
            $this->directory, (string) $user->id, $label, $plan, $mode,
        ], base_path(), timeout: 20);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function waitForDatabaseLock(string $label): void
    {
        $this->waitUntil(fn (): bool => DB::table('pg_stat_activity')
            ->where('application_name', $this->schema.'_'.$label)->where('wait_event_type', 'Lock')->exists());
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
