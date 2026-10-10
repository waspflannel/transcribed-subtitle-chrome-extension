<?php

namespace Tests\Feature;

use App\Ai\Agents\SubtitleAgent;
use App\Ai\SubtitleModel;
use App\Exceptions\SubtitleProcessingException;
use App\Services\ClaudeCode\ClaudeCodeService;
use App\Services\InstanceSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClaudeCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/transcribe-claude-test-'.Str::uuid();
        config([
            'claude-code.config_dir' => $this->dir,
            'claude-code.token' => 'test-token',
        ]);
        $this->scenario('success');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_structured_prompt_runs_an_isolated_cli_with_tools_settings_and_sessions_disabled(): void
    {
        $output = app(ClaudeCodeService::class)->prompt($this->agent(), ['private' => 'prompt-private'], new SubtitleModel('claude', 'sonnet'));

        $this->assertSame(['answer' => 'ok'], $output);
        $invocation = $this->invocation();
        $argv = $invocation['argv'];
        foreach ([['--model', 'sonnet'], ['--tools', ''], ['--setting-sources', ''], ['--permission-prompts', 'none'], ['--output-format', 'json']] as [$flag, $value]) {
            $this->assertSame($value, $argv[array_search($flag, $argv, true) + 1]);
        }
        foreach (['-p', '--strict-mcp-config', '--disable-slash-commands', '--no-session-persistence'] as $flag) {
            $this->assertContains($flag, $argv);
        }
        $this->assertStringStartsWith('Test subtitle instructions.', $argv[array_search('--system-prompt', $argv, true) + 1]);
        $schema = json_decode($argv[array_search('--json-schema', $argv, true) + 1], true);
        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['private' => 'prompt-private'], json_decode($invocation['stdin'], true));
        $this->assertSame(realpath($this->dir), realpath($invocation['cwd']));
    }

    public function test_child_environment_holds_only_the_token_and_private_config_dir(): void
    {
        putenv('TRANSCRIBE_TEST_SECRET=private');
        $_ENV['ANTHROPIC_API_KEY'] = 'paid-key';
        $_ENV['OPENAI_API_KEY'] = 'k';
        try {
            app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'sonnet'));
        } finally {
            putenv('TRANSCRIBE_TEST_SECRET');
            unset($_ENV['ANTHROPIC_API_KEY'], $_ENV['OPENAI_API_KEY']);
        }

        $env = $this->invocation()['env'];
        $this->assertSame('test-token', $env['CLAUDE_CODE_OAUTH_TOKEN']);
        foreach (['CLAUDE_CONFIG_DIR', 'HOME', 'USERPROFILE'] as $name) {
            $this->assertSame($this->dir, $env[$name]);
        }
        foreach (['ANTHROPIC_API_KEY', 'APP_KEY', 'DB_PASSWORD', 'OPENAI_API_KEY', 'TRANSCRIBE_TEST_SECRET'] as $name) {
            $this->assertFalse($env[$name]);
        }
    }

    public function test_aliases_use_the_5_5_models_at_low_effort_with_fast_mode_disabled(): void
    {
        app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'opus'));

        $invocation = $this->invocation();
        $argv = $invocation['argv'];
        $this->assertSame('low', $argv[array_search('--effort', $argv, true) + 1]);
        $this->assertSame('opus', $argv[array_search('--model', $argv, true) + 1]);
        $this->assertSame('claude-opus-5-5', $invocation['env']['ANTHROPIC_DEFAULT_OPUS_MODEL']);
        $this->assertSame('claude-sonnet-5-5', $invocation['env']['ANTHROPIC_DEFAULT_SONNET_MODEL']);
        $this->assertSame('claude-haiku-5-5', $invocation['env']['ANTHROPIC_DEFAULT_HAIKU_MODEL']);
        $this->assertSame('1', $invocation['env']['CLAUDE_CODE_DISABLE_FAST_MODE']);
        foreach (['--fast', '--fast-mode', '--enable-fast-mode'] as $flag) {
            $this->assertNotContains($flag, $argv);
        }
    }

    #[TestWith(['unauthorized', 'provider_not_configured'])]
    #[TestWith(['rate-limited', 'rate_limited'])]
    #[TestWith(['server-error', 'provider_unavailable'])]
    #[TestWith(['crash', 'provider_unavailable'])]
    #[TestWith(['oversized', 'provider_unavailable'])]
    public function test_cli_failures_map_to_safe_provider_errors(string $scenario, string $code): void
    {
        $this->scenario($scenario);

        try {
            app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'sonnet'));
            $this->fail('Expected a provider failure.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame($code, $exception->publicCode);
            $this->assertStringNotContainsString('raw-provider-secret', $exception->getMessage());
            $this->assertStringNotContainsString('raw-provider-secret', json_encode($exception->context));
        }
    }

    #[TestWith(['list-output'])]
    #[TestWith(['missing-output'])]
    public function test_invalid_structured_output_fails_enrichment(string $scenario): void
    {
        $this->scenario($scenario);

        try {
            app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'sonnet'));
            $this->fail('Expected invalid output.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('invalid_output', $exception->context['reason']);
        }
    }

    public function test_a_hung_cli_is_stopped_at_the_agent_timeout(): void
    {
        $this->scenario('hang');
        $start = microtime(true);

        try {
            app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'sonnet'));
            $this->fail('Expected a timeout.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_unavailable', $exception->publicCode);
        }

        $this->assertLessThan(6, microtime(true) - $start);
    }

    public function test_missing_token_never_starts_the_cli(): void
    {
        config(['claude-code.token' => null]);

        try {
            app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'sonnet'));
            $this->fail('Expected a missing token failure.');
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('provider_not_configured', $exception->publicCode);
        }

        $this->assertFileDoesNotExist($this->dir.'/invocation.json');
    }

    public function test_available_requires_the_minimum_cli_version(): void
    {
        $this->assertTrue(ClaudeCodeService::available());

        $this->scenario('old-version');
        Cache::flush();
        $this->assertFalse(ClaudeCodeService::available());

        config(['claude-code.command' => ['/nonexistent/claude']]);
        Cache::flush();
        $this->assertFalse(ClaudeCodeService::available());
    }

    public function test_settings_store_the_claude_token_encrypted_and_never_return_it(): void
    {
        config(['claude-code.token' => null]);
        $this->withExtensionInstall('install_claude_test')
            ->putJson('/v1/settings', ['providers' => ['claude' => ['apiKey' => 'claude-secret-token']]])
            ->assertOk()->assertDontSee('claude-secret-token');
        $this->getJson('/v1/settings')->assertOk()->assertDontSee('claude-secret-token')
            ->assertJsonPath('providers.claude', ['configured' => true, 'available' => true]);

        $this->assertStringNotContainsString('claude-secret-token', DB::table('instance_settings')->value('values'));
    }

    public function test_settings_strip_whitespace_from_a_pasted_token(): void
    {
        $this->withExtensionInstall('install_claude_test')
            ->putJson('/v1/settings', ['providers' => ['claude' => ['apiKey' => " sk-ant-oat01-abc def\n"]]])
            ->assertOk();

        app(InstanceSettings::class)->apply();
        $this->assertSame('sk-ant-oat01-abcdef', config('claude-code.token'));
    }

    public function test_claude_jobs_carry_the_selected_model_like_codex(): void
    {
        Queue::fake();
        config(['ai.providers.eleven.key' => 'eleven-key']);
        $this->withExtensionInstall('install_claude_test');

        $this->postJson('/v1/subtitle-jobs', [...$this->payload(), 'aiModel' => 'haiku'])->assertAccepted()
            ->assertJsonPath('aiProvider', 'claude')->assertJsonPath('aiModel', 'haiku');
        $payload = $this->payload();
        unset($payload['aiModel']);
        $this->postJson('/v1/subtitle-jobs', $payload)->assertUnprocessable();
        $this->postJson('/v1/subtitle-jobs', [...$this->payload(), 'aiModel' => 'claude-opus-4'])->assertUnprocessable();
        $this->postJson('/v1/subtitle-jobs', [...$this->payload(), 'aiFastMode' => true])->assertUnprocessable();
    }

    public function test_claude_generation_requires_a_token_and_a_supported_cli(): void
    {
        Queue::fake();
        config(['ai.providers.eleven.key' => 'eleven-key', 'claude-code.token' => null]);
        $this->withExtensionInstall('install_claude_test');

        $this->postJson('/v1/subtitle-jobs', $this->payload())->assertUnprocessable()
            ->assertJsonPath('error.code', 'provider_not_configured');

        config(['claude-code.token' => 'test-token']);
        $this->scenario('old-version');
        Cache::flush();
        $this->postJson('/v1/subtitle-jobs', $this->payload())->assertUnprocessable()
            ->assertJsonPath('error.code', 'provider_not_configured');
    }

    private function payload(): array
    {
        return [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            'includeRomanization' => false,
            'includeTranslation' => false,
            'aiProvider' => 'claude',
            'aiModel' => 'sonnet',
        ];
    }

    private function scenario(string $name): void
    {
        config(['claude-code.command' => [PHP_BINARY, base_path('tests/Fixtures/claude-code-cli.php'), $name]]);
    }

    private function invocation(): array
    {
        return json_decode(file_get_contents($this->dir.'/invocation.json'), true);
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
