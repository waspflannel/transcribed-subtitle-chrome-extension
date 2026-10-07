<?php

namespace Tests\Feature;

use App\Ai\Agents\SubtitleAgent;
use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Jobs\ConnectCodexAccount;
use App\Services\Codex\CodexService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class CodexServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $home;

    private int $callbackPort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/transcribe-codex-test-'.Str::uuid();
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $this->callbackPort = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $this->app->bind(CodexService::class, fn (): CodexService => new class($this->callbackPort) extends CodexService
        {
            public function __construct(private readonly int $port) {}

            protected function callbackPort(): int
            {
                return $this->port;
            }
        });
        config([
            'codex.home' => $this->home,
            'codex.command' => [PHP_BINARY, base_path('tests/Fixtures/codex-app-server.php'), 'success'],
            'codex.request_timeout_seconds' => 3,
            'codex.login_timeout_seconds' => 5,
        ]);
        Bus::fake([ConnectCodexAccount::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    public function test_login_is_queued_once_and_returns_only_safe_dynamic_models(): void
    {
        $codex = app(CodexService::class);
        $this->assertSame('pending', $codex->startLogin()['login']['status']);
        $codex->startLogin();
        Bus::assertDispatchedTimes(ConnectCodexAccount::class, 1);
        $this->completeLogin($codex);
        $loginRequest = collect($this->requests())->firstWhere('method', 'account/login/start');
        $this->assertSame(['type' => 'chatgpt'], $loginRequest['params']);
        $published = json_decode(file_get_contents($this->home.'/published-login.json'), true);
        $this->assertSame([
            'status' => 'awaiting_authorization',
            'authUrl' => 'https://auth.openai.com/oauth/authorize?state=test-state&code_challenge=test-challenge',
        ], $published['login']);
        $summary = $codex->summary();
        $this->assertTrue($summary['connected']);
        $this->assertNull($summary['login']);
        $this->assertSame([
            ['id' => 'gpt-test', 'name' => 'Test Model', 'supportsFastMode' => true],
            ['id' => 'gpt-slow', 'name' => 'Slow Model', 'supportsFastMode' => false],
            ['id' => 'gpt-current', 'name' => 'Current Model', 'supportsFastMode' => true],
        ], $summary['models']);
        $this->assertStringNotContainsString('not-returned@example.invalid', json_encode($summary));
        $codex->validateSelection('gpt-test', true);
        $codex->validateSelection('gpt-current', true);
        $this->expectException(SubtitleProcessingException::class);
        $this->expectExceptionMessage('Choose an available');
        $codex->validateSelection('gpt-slow', true);
    }

    public function test_structured_prompt_uses_selected_speed_ephemeral_history_and_restricted_access(): void
    {
        $codex = $this->connected();
        $result = $codex->prompt($this->agent(), ['private' => 'prompt-private'], new SubtitleModel('codex', 'gpt-test', true));
        $this->assertSame(['answer' => 'ok'], $result);
        $requests = $this->requests();
        $thread = collect($requests)->firstWhere('method', 'thread/start')['params'];
        $turn = collect($requests)->firstWhere('method', 'turn/start')['params'];
        $this->assertTrue($thread['ephemeral']);
        $this->assertFalse($thread['persistExtendedHistory']);
        $this->assertSame('Test subtitle instructions.', $thread['baseInstructions']);
        $this->assertSame('fast', $thread['serviceTier']);
        $this->assertSame('gpt-test', $thread['model']);
        $this->assertFalse($thread['config']['mcp_servers."unsafe".enabled']);
        $this->assertSame('fast', $turn['serviceTier']);
        $this->assertSame(['type' => 'restricted', 'includePlatformDefaults' => false, 'readableRoots' => []], $turn['sandboxPolicy']['access']);
        $this->assertFalse($turn['sandboxPolicy']['networkAccess']);
        $this->assertSame('object', $turn['outputSchema']['type']);
        $this->assertFalse($turn['outputSchema']['additionalProperties']);
    }

    public function test_disconnect_removes_the_account_and_rejects_further_work(): void
    {
        $codex = $this->connected();
        $state = json_decode(file_get_contents($this->home.'/connection.json'), true);
        $codex->disconnect();
        $this->assertDirectoryDoesNotExist($this->home.'/'.$state['active']);
        $this->assertFalse($codex->summary()['connected']);
        $this->expectException(SubtitleProcessingException::class);
        $this->expectExceptionMessage('Sign in to Codex');
        $codex->requireConnected();
    }

    public function test_cancelled_login_cannot_publish_a_late_success(): void
    {
        $this->scenario('cancel');
        $codex = app(CodexService::class);
        $codex->startLogin();
        $this->completeLogin($codex);
        $this->assertFalse($codex->summary()['connected']);
        $this->assertSame([], File::directories($this->home));
    }

    public function test_login_failure_does_not_return_raw_provider_errors(): void
    {
        $this->scenario('login-failure');
        $codex = app(CodexService::class);
        $codex->startLogin();
        $this->completeLogin($codex);
        $this->assertSame('failed', $codex->summary()['login']['status']);
        $this->assertStringNotContainsString('secret-provider-error', file_get_contents($this->home.'/connection.json'));
    }

    public function test_browser_authorization_url_is_returned_without_legacy_device_fields(): void
    {
        $this->connected();
        $published = json_decode(file_get_contents($this->home.'/published-login.json'), true);
        file_put_contents($this->home.'/connection.json', json_encode($published));
        $this->withExtensionInstall('codex_browser_test')->getJson('/v1/codex')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('login.status', 'awaiting_authorization')
            ->assertJsonPath('login.authUrl', $published['login']['authUrl'])
            ->assertJsonMissingPath('login.verificationUrl')->assertJsonMissingPath('login.userCode');
    }

    public function test_browser_login_rejects_unsafe_authorization_urls(): void
    {
        $codex = app(CodexService::class);
        foreach ([
            'http://auth.openai.com/oauth/authorize',
            'https://auth.openai.com.attacker.invalid/oauth/authorize',
            'https://name:password@auth.openai.com/oauth/authorize',
            'https://auth.openai.com:443/oauth/authorize',
            'https://chatgpt.com\\@attacker.invalid/oauth/authorize',
            'https://auth.openai.com/oauth/authorize?state=with space',
            "https://auth.openai.com/oauth/authorize\n",
            'javascript:alert(1)',
            'https://auth.openai.com/'.str_repeat('a', 4096),
        ] as $url) {
            $this->scenario('url:'.base64_encode($url));
            $codex->startLogin();
            $this->completeLogin($codex);
            $summary = $codex->summary();
            $this->assertSame(['status' => 'failed'], $summary['login']);
            $this->assertFalse($summary['connected']);
            $this->assertStringNotContainsString($url, file_get_contents($this->home.'/connection.json'));
        }
    }

    public function test_browser_login_accepts_the_official_chatgpt_host(): void
    {
        $this->scenario('url:'.base64_encode('https://chatgpt.com/oauth/authorize?state=test'));
        $codex = $this->connected();
        $this->assertTrue($codex->summary()['connected']);
    }

    public function test_old_device_login_is_immediately_restartable_and_cannot_complete(): void
    {
        $codex = app(CodexService::class);
        $codex->startLogin();
        $oldJob = Bus::dispatched(ConnectCodexAccount::class)->last();
        $state = json_decode(file_get_contents($this->home.'/connection.json'), true);
        unset($state['flow']);
        $state['login'] = ['status' => 'awaiting_authorization', 'verificationUrl' => 'https://auth.openai.com/codex/device', 'userCode' => 'OLD-CODE'];
        file_put_contents($this->home.'/connection.json', json_encode($state));
        $this->assertSame(['status' => 'failed'], $codex->summary()['login']);
        $this->assertSame(['status' => 'pending'], $codex->startLogin()['login']);
        Bus::assertDispatchedTimes(ConnectCodexAccount::class, 2);
        $oldJob->handle($codex);
        $this->assertFileDoesNotExist($this->home.'/requests.jsonl');
        $this->completeLogin($codex);
        $this->assertTrue($codex->summary()['connected']);
    }

    public function test_busy_callback_port_never_starts_or_cancels_another_login(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:'.$this->callbackPort);
        try {
            $codex = app(CodexService::class);
            $codex->startLogin();
            $this->completeLogin($codex);
            $this->assertSame(['status' => 'failed'], $codex->summary()['login']);
            $this->assertFileDoesNotExist($this->home.'/requests.jsonl');
        } finally {
            fclose($listener);
        }
    }

    public function test_cancelled_browser_listener_is_closed_before_a_retry_owns_the_lock(): void
    {
        $this->scenario('cancel-pending');
        $codex = app(CodexService::class);
        $codex->startLogin();
        $started = microtime(true);
        $this->completeLogin($codex);
        $this->assertLessThan(4, microtime(true) - $started);
        $this->assertNull(collect($this->requests())->firstWhere('method', 'account/login/cancel'));
        $lock = fopen($this->home.'/login.lock', 'c+');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->scenario('success');
        $codex->startLogin();
        $this->completeLogin($codex);
        $this->assertTrue($codex->summary()['connected']);
    }

    public function test_an_existing_login_owner_blocks_new_callback_start_for_a_bounded_time(): void
    {
        config(['codex.request_timeout_seconds' => 1]);
        $codex = app(CodexService::class);
        $codex->startLogin();
        $lock = fopen($this->home.'/login.lock', 'c+');
        flock($lock, LOCK_EX);
        $started = microtime(true);
        try {
            $this->completeLogin($codex);
            $this->assertLessThan(3, microtime(true) - $started);
            $this->assertFileDoesNotExist($this->home.'/requests.jsonl');
            $this->assertSame(['status' => 'failed'], $codex->summary()['login']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_expired_pending_login_is_not_left_spinning(): void
    {
        $codex = app(CodexService::class);
        $codex->startLogin();
        $state = json_decode(file_get_contents($this->home.'/connection.json'), true);
        $state['expiresAt'] = time() - 1;
        file_put_contents($this->home.'/connection.json', json_encode($state));
        $this->assertSame('failed', $codex->summary()['login']['status']);
    }

    public function test_missing_cli_is_reported_as_unavailable(): void
    {
        config(['codex.command' => [$this->home.'/missing-codex']]);
        $summary = app(CodexService::class)->summary();
        $this->assertFalse($summary['available']);
        $this->assertFalse($summary['connected']);
        $this->assertSame([], $summary['models']);
    }

    public function test_api_key_auth_cannot_be_used_for_a_codex_job(): void
    {
        $codex = $this->connected();
        $this->scenario('api-key');
        $this->expectException(SubtitleProcessingException::class);
        $this->expectExceptionMessage('Reconnect Codex');
        $codex->prompt($this->agent(), [], new SubtitleModel('codex', 'gpt-test'));
    }

    public function test_process_errors_invalid_json_tools_and_failed_turns_are_sanitized(): void
    {
        $codex = $this->connected();
        foreach (['process-failure', 'invalid-json', 'tool-request', 'turn-failure'] as $scenario) {
            $this->scenario($scenario);
            try {
                $codex->prompt($this->agent(), ['private' => 'prompt-private'], new SubtitleModel('codex', 'gpt-test'));
                $this->fail('Expected a safe failure for '.$scenario);
            } catch (SubtitleProcessingException $exception) {
                $this->assertStringNotContainsString('secret-provider-error', (string) $exception);
                $this->assertStringNotContainsString('prompt-private', (string) $exception);
                $this->assertNull($exception->getPrevious());
                if ($scenario === 'invalid-json') {
                    $this->assertFalse($exception->isTransient());
                    $this->assertSame('enrichment_failed', $exception->publicCode);
                }
            }
        }
    }

    public function test_account_endpoints_are_private_and_do_not_cache_authorization_urls(): void
    {
        $this->withExtensionInstall('codex_test_install');
        $this->getJson('/v1/codex')->assertOk()->assertJsonPath('available', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/v1/codex/login')->assertAccepted()->assertJsonPath('login.status', 'pending');
        $this->deleteJson('/v1/codex')->assertOk()->assertJsonPath('connected', false)->assertJsonPath('login', null);
    }

    public function test_account_routes_require_install_id_and_a_trusted_origin_and_throttle_login(): void
    {
        $this->getJson('/v1/codex')->assertUnprocessable();
        $this->withExtensionInstall('codex_boundary_test')->withHeader('Origin', 'https://attacker.invalid')
            ->postJson('/v1/codex/login')->assertForbidden();
        $this->withHeader('Origin', 'http://localhost');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/codex/login')->assertAccepted();
        }
        $this->postJson('/v1/codex/login')->assertTooManyRequests();
    }

    public function test_disconnect_before_the_turn_prevents_unsent_work_and_cleans_retired_home(): void
    {
        $codex = $this->connected();
        $this->scenario('disconnect-before-turn');
        try {
            $codex->prompt($this->agent(), [], new SubtitleModel('codex', 'gpt-test'));
            $this->fail('Expected disconnect failure');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_not_configured', $exception->publicCode);
        }
        $this->assertNull(collect($this->requests())->firstWhere('method', 'turn/start'));
        $this->assertSame([], File::directories($this->home));
    }

    public function test_quota_auth_and_rate_limits_keep_distinct_safe_failures(): void
    {
        foreach (['quota' => 'enrichment_failed', 'rate-limit' => 'rate_limited', 'unauthorized' => 'provider_not_configured'] as $scenario => $code) {
            $this->scenario('success');
            $codex = $this->connected();
            $this->scenario($scenario);
            try {
                $codex->prompt($this->agent(), [], new SubtitleModel('codex', 'gpt-test'));
                $this->fail('Expected '.$code);
            } catch (SubtitleProcessingException $exception) {
                $this->assertSame($code, $exception->publicCode);
                $this->assertStringNotContainsString('secret-provider-error', (string) $exception);
            } finally {
                $codex->disconnect();
            }
        }
    }

    public function test_inherited_service_environment_is_removed(): void
    {
        putenv('TRANSCRIBE_TEST_SECRET=private');
        $_ENV['TRANSCRIBE_TEST_ENV_SECRET'] = 'private-env-only';
        $originalEnv = $_ENV;
        $originalServer = $_SERVER;
        $_ENV['OPENAI_API_KEY'] = 'dotenv-only-key';
        $_SERVER['CODEX_ACCESS_TOKEN'] = 'server-only-token';
        try {
            $this->connected();
            $environment = json_decode(file_get_contents($this->home.'/environment.json'), true);
            $this->assertFalse($environment['TRANSCRIBE_TEST_SECRET']);
            $this->assertFalse($environment['TRANSCRIBE_TEST_ENV_SECRET']);
            $this->assertFalse($environment['OPENAI_API_KEY']);
            $this->assertFalse($environment['CODEX_ACCESS_TOKEN']);
            $this->assertFalse($environment['OPENAI_BASE_URL']);
            $this->assertSame($environment['CODEX_HOME'], $environment['HOME']);
            $this->assertStringStartsWith($this->home.'/', $environment['CODEX_HOME']);
        } finally {
            putenv('TRANSCRIBE_TEST_SECRET');
            $_ENV = $originalEnv;
            $_SERVER = $originalServer;
            unset($_ENV['TRANSCRIBE_TEST_ENV_SECRET']);
        }
    }

    public function test_stalled_partial_protocol_frame_has_a_bounded_safe_failure(): void
    {
        $codex = $this->connected();
        $this->scenario('stall');
        $started = microtime(true);
        try {
            $codex->prompt($this->agent(), [], new SubtitleModel('codex', 'gpt-test'));
            $this->fail('Expected timeout');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_unavailable', $exception->publicCode);
            $this->assertLessThan(6, microtime(true) - $started);
        }
    }

    private function connected(): CodexService
    {
        $codex = app(CodexService::class);
        $codex->startLogin();
        $this->completeLogin($codex);

        return $codex;
    }

    private function completeLogin(CodexService $codex): void
    {
        $job = Bus::dispatched(ConnectCodexAccount::class)->last();
        $job->handle($codex);
    }

    private function scenario(string $scenario): void
    {
        config(['codex.command' => [PHP_BINARY, base_path('tests/Fixtures/codex-app-server.php'), $scenario]]);
    }

    private function requests(): array
    {
        return array_map(fn (string $line): array => json_decode($line, true), file($this->home.'/requests.jsonl', FILE_IGNORE_NEW_LINES));
    }

    private function agent(): SubtitleAgent
    {
        return new class extends SubtitleAgent
        {
            public function instructions(): string
            {
                return 'Test subtitle instructions.';
            }

            public function timeout(): int
            {
                return 3;
            }

            public function schema(JsonSchema $schema): array
            {
                return ['answer' => $schema->string()->required()];
            }
        };
    }
}
