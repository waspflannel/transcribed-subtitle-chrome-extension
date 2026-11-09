<?php

namespace Tests\Feature;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\SubtitleAgent;
use App\Ai\SubtitleModel;
use App\Jobs\ConnectClaudeAccount;
use App\Services\ClaudeCode\ClaudeAccountService;
use App\Services\ClaudeCode\ClaudeCodeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

class ClaudeAccountTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/transcribe-claude-account-'.Str::uuid();
        config(['claude-code.config_dir' => $this->dir, 'claude-code.token' => null]);
        $this->scenario('success');
        Cache::flush();
        Queue::fake();
        $this->withExtensionInstall('install_claude_account');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_sign_in_runs_claude_auth_login_and_the_signed_in_directory_runs_prompts(): void
    {
        $this->getJson('/v1/claude')->assertOk()->assertJson(['available' => true, 'connected' => false, 'email' => null, 'login' => null]);

        $this->postJson('/v1/claude/login')->assertAccepted()->assertJsonPath('login.status', 'pending');
        $attempt = $this->pushedAttempt();
        $this->seedCode('good-code#s');

        app(ClaudeAccountService::class)->connect($attempt);

        $this->getJson('/v1/claude')->assertOk()->assertJson(['connected' => true, 'email' => 'me@example.com', 'login' => null]);
        $this->getJson('/v1/settings')->assertJsonPath('providers.claude.configured', true);
        $this->assertStringNotContainsString('good-code', (string) file_get_contents($this->dir.'/accounts/account.json'));

        app(ClaudeCodeService::class)->prompt($this->agent(), [], new SubtitleModel('claude', 'claude-opus-5'));
        $invocation = json_decode(file_get_contents($this->dir.'/accounts/'.$attempt.'/invocation.json'), true);
        $this->assertSame('claude-opus-5', $invocation['argv'][array_search('--model', $invocation['argv'], true) + 1]);
        $this->assertFalse($invocation['env']['CLAUDE_CODE_OAUTH_TOKEN']);

        $this->deleteJson('/v1/claude')->assertOk()->assertJson(['connected' => false, 'email' => null]);
        $this->assertDirectoryDoesNotExist($this->dir.'/accounts/'.$attempt);
    }

    public function test_a_wrong_code_fails_the_attempt_and_removes_its_directory(): void
    {
        $this->postJson('/v1/claude/login')->assertAccepted();
        $attempt = $this->pushedAttempt();
        $this->seedCode('wrong#s');

        app(ClaudeAccountService::class)->connect($attempt);

        $this->getJson('/v1/claude')->assertJson(['connected' => false, 'login' => ['status' => 'failed']]);
        $this->assertDirectoryDoesNotExist($this->dir.'/accounts/'.$attempt);
    }

    public function test_an_untrusted_sign_in_url_is_never_shown(): void
    {
        $this->scenario('untrusted-url');
        $this->postJson('/v1/claude/login')->assertAccepted();
        $this->seedCode('good-code#s');

        app(ClaudeAccountService::class)->connect($this->pushedAttempt());

        $this->getJson('/v1/claude')->assertJson(['connected' => false, 'login' => ['status' => 'failed']])->assertDontSee('evil');
    }

    public function test_codes_are_accepted_only_while_the_cli_waits_for_one(): void
    {
        $this->postJson('/v1/claude/login/code', ['code' => 'abc#def'])->assertUnprocessable();
        $this->postJson('/v1/claude/login/code', ['code' => 'abc def<script>'])->assertUnprocessable();
        $this->postJson('/v1/claude/login/code', [])->assertUnprocessable();
    }

    public function test_claude_api_jobs_pin_the_model_and_allow_fast_mode_only_on_supported_models(): void
    {
        config(['ai.providers.eleven.key' => 'eleven-key', 'ai.providers.anthropic.key' => 'sk-ant-test']);
        $payload = [
            'youtubeVideoId' => 'dQw4w9WgXcQ',
            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'sourceLanguage' => 'auto',
            'targetLanguage' => 'eng',
            'includeRomanization' => false,
            'includeTranslation' => false,
            'aiProvider' => 'anthropic',
        ];

        $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiModel' => 'claude-opus-5-5', 'aiFastMode' => true])->assertAccepted()
            ->assertJsonPath('aiProvider', 'anthropic')->assertJsonPath('aiModel', 'claude-opus-5-5')->assertJsonPath('aiFastMode', true);
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiModel' => 'claude-sonnet-5-5', 'aiFastMode' => true])->assertUnprocessable();
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiModel' => 'sonnet'])->assertUnprocessable();
        $this->postJson('/v1/subtitle-jobs', $payload)->assertUnprocessable();

        config(['ai.providers.anthropic.key' => null]);
        $this->postJson('/v1/subtitle-jobs', [...$payload, 'aiModel' => 'claude-haiku-5-5', 'forceRegenerate' => true])->assertUnprocessable();
    }

    public function test_fast_mode_adds_speed_only_for_the_claude_api(): void
    {
        $agent = $this->agent();
        $this->assertArrayNotHasKey('speed', $agent->providerOptions(Lab::Anthropic));
        $agent->fastMode = true;
        $this->assertSame('fast', $agent->providerOptions(Lab::Anthropic)['speed']);
        $this->assertArrayNotHasKey('speed', $agent->providerOptions('openai'));
    }

    public function test_claude_api_schemas_drop_limits_anthropic_rejects(): void
    {
        $agent = CueAnalysisAgent::make(sourceLanguage: 'eng', includeTranslation: true, includeRomanization: true);
        $options = $agent->providerOptions(Lab::Anthropic);

        $this->assertSame('low', $options['output_config']['effort']);
        $this->assertSame('json_schema', $options['output_config']['format']['type']);
        $schema = json_encode($options['output_config']['format']['schema']);
        $this->assertStringContainsString('"cues"', $schema);
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems'] as $limit) {
            $this->assertStringNotContainsString('"'.$limit.'"', $schema);
        }
        $this->assertArrayNotHasKey('output_config', $agent->providerOptions('openai'));
    }

    public function test_settings_store_the_claude_api_key_without_returning_it(): void
    {
        $this->putJson('/v1/settings', ['providers' => ['anthropic' => ['apiKey' => 'sk-ant-private']]])->assertOk()
            ->assertJsonPath('providers.anthropic', ['configured' => true])->assertDontSee('sk-ant-private');
    }

    private function pushedAttempt(): string
    {
        $attempt = null;
        Queue::assertPushed(ConnectClaudeAccount::class, function (ConnectClaudeAccount $job) use (&$attempt): bool {
            $attempt = $job->attempt;

            return true;
        });

        return $attempt;
    }

    /** The code normally arrives from a second request while the job waits; seed it so the job runs straight through. */
    private function seedCode(string $code): void
    {
        $path = $this->dir.'/accounts/account.json';
        file_put_contents($path, json_encode([...json_decode(file_get_contents($path), true), 'code' => $code]));
    }

    private function scenario(string $name): void
    {
        config(['claude-code.command' => [PHP_BINARY, base_path('tests/Fixtures/claude-code-cli.php'), $name]]);
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
                return 5;
            }

            public function schema(JsonSchema $schema): array
            {
                return ['answer' => $schema->string()->required()];
            }
        };
    }
}
