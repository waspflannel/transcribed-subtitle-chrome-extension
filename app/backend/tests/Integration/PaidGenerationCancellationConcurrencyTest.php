<?php

namespace Tests\Integration;

use App\Models\BillingUsageEvent;
use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalog;
use App\Services\Billing\UsageLedger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaidGenerationCancellationConcurrencyTest extends TestCase
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
        $this->schema = 'paid_work_test_'.bin2hex(random_bytes(8));
        $this->directory = SUBTITLE_TEST_STORAGE.'/'.$this->schema;
        mkdir($this->directory, 0700);
        $connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port,
            'database' => $database, 'username' => (string) getenv('SUBTITLE_DISPOSABLE_PG_USERNAME'),
            'password' => (string) getenv('SUBTITLE_DISPOSABLE_PG_PASSWORD'),
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema,
            'sslmode' => 'disable', 'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
        config(['database.connections.paid_work_test' => $connection, 'database.default' => 'paid_work_test']);
        DB::purge('paid_work_test');
        DB::statement('CREATE SCHEMA '.$this->schema);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'paid_work_test', '--force' => true]));
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
            DB::purge('paid_work_test');
        }
        parent::tearDown();
    }

    #[TestWith(['cancel'])]
    #[TestWith(['delete'])]
    public function test_voluntary_stop_winning_the_lock_refunds_and_prevents_provider_dispatch(string $action): void
    {
        $job = $this->reservedJob();
        $stop = $this->startWorker($job, 'stop', $action.'-paused');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/stop-settlement-paused'));
        $provider = $this->startWorker($job, 'provider', 'request');
        $this->waitForDatabaseLock('provider');
        touch($this->directory.'/stop-settlement-release');
        $this->assertWorkerSucceeded($stop);
        $this->assertWorkerSucceeded($provider);
        $this->assertStringContainsString('request-fenced', $provider->getOutput());
        $this->assertFileDoesNotExist($this->directory.'/provider-requests');
        $this->assertNull($job->fresh()?->paid_work_started_at);
        $this->assertSettlement($job, 'refund', 0);
    }

    #[TestWith(['cancel'])]
    #[TestWith(['delete'])]
    public function test_paid_dispatch_winning_the_lock_charges_once_while_http_is_still_in_flight(string $action): void
    {
        $job = $this->reservedJob();
        $provider = $this->startWorker($job, 'provider', 'request-paused');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/provider-marker-paused'));
        $stop = $this->startWorker($job, 'stop', $action);
        $this->waitForDatabaseLock('stop');
        touch($this->directory.'/provider-marker-release');
        $this->waitUntil(fn (): bool => is_file($this->directory.'/provider-request-paused'));
        $this->assertWorkerSucceeded($stop);
        $this->assertTrue($provider->isRunning());
        $this->assertSettlement($job, 'debit', 4);
        touch($this->directory.'/provider-request-release');
        $this->assertWorkerSucceeded($provider);
        $this->assertSame(['request'], file($this->directory.'/provider-requests', FILE_IGNORE_NEW_LINES));
        $this->assertSettlement($job, 'debit', 4);
    }

    private function reservedJob(): SubtitleJob
    {
        $user = User::factory()->create([
            'billing_plan_code' => 'base', 'billing_subscription_status' => 'active',
            'billing_current_period_start' => now()->startOfMonth(),
            'billing_current_period_end' => now()->addMonthNoOverflow()->startOfMonth(),
        ]);
        $job = SubtitleJob::factory()->for($user)->create();
        app(UsageLedger::class)->reserveForJob($job, $user, app(BillingPlanCatalog::class)->requirePlan('base'), 4);

        return $job;
    }

    private function assertSettlement(SubtitleJob $job, string $type, int $charged): void
    {
        $event = BillingUsageEvent::where('idempotency_key', 'settlement:'.$job->id.':'.$job->run_id)->sole();
        $this->assertSame($type, $event->event_type);
        $this->assertSame($charged, $event->used_minutes_delta);
        $this->assertSame(-$charged, $event->available_minutes_delta);
        $this->assertSame(-4, $event->reserved_minutes_delta);
    }

    private function startWorker(SubtitleJob $job, string $label, string $mode): Process
    {
        $worker = new Process([
            PHP_BINARY, base_path('tests/Fixtures/paid-generation-worker.php'),
            $this->directory, (string) $job->id, $label, $mode,
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
