<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Models\InstanceSetting;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Tests\TestCase;

class InstanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_encrypt_keys_and_never_return_them(): void
    {
        $this->withHeader('X-Extension-Install-Id', 'install_settings_test');
        $this->putJson('/v1/settings', [
            'providers' => ['openai' => ['apiKey' => 'test-private-key']],
            'retentionDays' => 90,
        ])->assertOk()->assertJsonPath('providers.openai.configured', true)
            ->assertJsonPath('providers.openai.model', 'gpt-6-luna')
            ->assertJsonPath('retentionDays', 90)->assertDontSee('test-private-key');

        $stored = DB::table('instance_settings')->value('values');
        $this->assertStringNotContainsString('test-private-key', $stored);
        $this->assertStringNotContainsString('test-private-key', InstanceSetting::query()->first()->toJson());
        $this->getJson('/v1/settings')->assertOk()->assertDontSee('test-private-key');
        $this->assertSame('test-private-key', config('ai.providers.openai.key'));
    }

    public function test_blank_keys_keep_configuration_and_null_explicitly_clears_it(): void
    {
        config(['ai.providers.openai.key' => 'environment-secret']);
        $this->withHeader('X-Extension-Install-Id', 'install_settings_test');
        $this->getJson('/v1/settings')->assertJsonPath('providers.openai.configured', true)->assertJsonPath('retentionDays', null);
        $this->putJson('/v1/settings', ['providers' => ['openai' => ['apiKey' => '  ']]])
            ->assertOk()->assertJsonPath('providers.openai.configured', true);
        $this->assertSame('environment-secret', config('ai.providers.openai.key'));
        $this->putJson('/v1/settings', ['providers' => ['openai' => ['apiKey' => null]], 'retentionDays' => null])
            ->assertOk()->assertJsonPath('providers.openai.configured', false)->assertJsonPath('retentionDays', null);
        $this->assertNull(config('ai.providers.openai.key'));
    }

    public function test_validation_rejects_unsupported_provider_fields_and_invalid_retention_without_echoing_keys(): void
    {
        $this->withHeader('X-Extension-Install-Id', 'install_settings_test')
            ->putJson('/v1/settings', [
                'providers' => ['openai' => ['apiKey' => 'do-not-echo', 'url' => 'https://attacker.invalid']],
                'retentionDays' => 0,
            ])->assertUnprocessable()->assertDontSee('do-not-echo');
        $this->assertDatabaseCount('instance_settings', 0);
    }

    public function test_existing_worker_process_reloads_database_settings_and_forgets_cached_ai_provider(): void
    {
        $settings = app(InstanceSettings::class);
        $settings->update(['providers' => ['openai' => ['apiKey' => 'first-key']]]);
        $firstProvider = app(AiManager::class)->textProvider('openai');
        InstanceSetting::query()->findOrFail(1)->update(['values' => [
            'providers' => ['openai' => ['apiKey' => 'rotated-key', 'model' => 'new-model']],
        ]]);
        $settings->apply();
        $this->assertSame('rotated-key', config('ai.providers.openai.key'));
        $this->assertSame('gpt-6-luna', config('ai.providers.openai.models.text.default'));
        $this->assertNotSame($firstProvider, app(AiManager::class)->textProvider('openai'));
    }

    public function test_generation_requires_the_selected_provider_key(): void
    {
        config(['ai.providers.eleven.key' => 'eleven-key', 'ai.providers.openai.key' => null]);
        $this->expectException(SubtitleProcessingException::class);
        $this->expectExceptionMessage('Configure the openai API key');
        app(InstanceSettings::class)->requireGenerationKeys('openai');
    }

    public function test_models_are_fixed_and_legacy_saved_overrides_are_ignored(): void
    {
        InstanceSetting::query()->create(['id' => 1, 'values' => ['providers' => [
            'openai' => ['model' => 'old-openai'],
            'cerebras' => ['model' => 'old-cerebras'],
            'elevenlabs' => ['model' => 'old-elevenlabs'],
        ]]]);
        $this->withExtensionInstall('install_settings_test')->getJson('/v1/settings')->assertOk()
            ->assertJsonPath('providers.openai.model', 'gpt-6-luna')
            ->assertJsonPath('providers.cerebras.model', 'gpt-oss-120b')
            ->assertJsonPath('providers.elevenlabs.model', 'scribe_v2');
        foreach (['openai', 'cerebras', 'elevenlabs'] as $provider) {
            $this->putJson('/v1/settings', ['providers' => [$provider => ['model' => 'custom-model']]])
                ->assertUnprocessable();
        }
    }

    public function test_settings_reject_retired_routing_provider_and_return_only_supported_providers(): void
    {
        $this->withExtensionInstall('install_settings_test')
            ->putJson('/v1/settings', ['providers' => ['typesafe' => ['apiKey' => 'never-save-this-key']]])
            ->assertUnprocessable()->assertDontSee('never-save-this-key');
        $this->assertDatabaseCount('instance_settings', 0);
        $this->getJson('/v1/settings')->assertOk()->assertJsonCount(4, 'providers')->assertJsonMissingPath('providers.claude.model')
            ->assertJsonMissingPath('providers.typesafe');
    }

    public function test_claude_settings_save_only_the_token_and_never_return_it(): void
    {
        $this->withExtensionInstall('install_settings_test')->getJson('/v1/settings')->assertOk()
            ->assertJsonMissingPath('providers.claude.model')->assertJsonMissingPath('providers.claude.thinking');

        $this->putJson('/v1/settings', ['providers' => ['claude' => ['apiKey' => 'claude-private-token']]])
            ->assertOk()->assertJsonPath('providers.claude.configured', true)
            ->assertDontSee('claude-private-token');
        $this->assertStringNotContainsString('claude-private-token', DB::table('instance_settings')->value('values'));

        config(['claude-code.token' => null]);
        app(InstanceSettings::class)->apply();
        $this->assertSame('claude-private-token', config('claude-code.token'));
    }

    public function test_claude_settings_reject_the_removed_model_and_thinking_fields(): void
    {
        $this->withExtensionInstall('install_settings_test');
        foreach ([['model' => 'sonnet'], ['thinking' => 'medium']] as $fields) {
            $this->putJson('/v1/settings', ['providers' => ['claude' => $fields]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('instance_settings', 0);
    }

    public function test_retention_changes_update_existing_tracks_and_completed_jobs_from_generation_time(): void
    {
        $this->freezeTime();
        $job = SubtitleJob::factory()->create(['status' => 'completed', 'expires_at' => now()->addDays(30)]);
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $job->id, 'generated_at' => now()->subDays(3)]);
        $settings = app(InstanceSettings::class);
        $settings->update(['retentionDays' => null]);
        $this->assertNull($job->refresh()->expires_at);
        $this->assertNull($track->refresh()->expires_at);
        $settings->update(['retentionDays' => 7]);
        $this->assertSame(now()->addDays(4)->toDateTimeString(), $job->refresh()->expires_at->toDateTimeString());
        $this->assertSame(now()->addDays(4)->toDateTimeString(), $track->refresh()->expires_at->toDateTimeString());
    }
}
