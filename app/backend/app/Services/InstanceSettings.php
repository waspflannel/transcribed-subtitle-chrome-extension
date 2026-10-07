<?php

namespace App\Services;

use App\Exceptions\SubtitleProcessingException;
use App\Models\InstanceSetting;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Codex\CodexService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiManager;

class InstanceSettings
{
    private const PROVIDERS = [
        'openai' => ['key' => 'ai.providers.openai.key', 'model' => 'ai.providers.openai.models.text.default'],
        'cerebras' => ['key' => 'ai.providers.cerebras.key', 'model' => 'ai.providers.cerebras.models.text.default'],
        'elevenlabs' => ['key' => 'ai.providers.eleven.key', 'model' => 'ai.providers.eleven.models.transcription.default'],
    ];

    public function apply(): void
    {
        $values = $this->stored();
        foreach (self::PROVIDERS as $provider => $paths) {
            $changed = false;
            if (array_key_exists('apiKey', $values['providers'][$provider] ?? [])) {
                $value = $values['providers'][$provider]['apiKey'];
                $changed = config($paths['key']) !== $value;
                config([$paths['key'] => $value]);
            }
            if ($changed) {
                app(AiManager::class)->forgetInstance($provider === 'elevenlabs' ? 'eleven' : $provider);
            }
        }
    }

    public function summary(): array
    {
        $this->apply();
        $providers = [];
        foreach (self::PROVIDERS as $provider => $paths) {
            $providers[$provider] = [
                'configured' => filled(config($paths['key'])),
                'model' => (string) config($paths['model']),
            ];
        }

        return ['providers' => $providers, 'retentionDays' => $this->retentionDays()];
    }

    public function update(array $patch): array
    {
        DB::transaction(function () use ($patch): void {
            $settings = $this->lockForUpdate();
            $values = $settings->values;
            foreach ($patch['providers'] ?? [] as $provider => $fields) {
                foreach ($fields as $field => $value) {
                    if ($field === 'apiKey' && is_string($value) && trim($value) === '') {
                        continue;
                    }
                    $values['providers'][$provider][$field] = $value;
                }
            }
            if (array_key_exists('retentionDays', $patch)) {
                $values['retentionDays'] = $patch['retentionDays'];
                SubtitleTrack::query()->select(['id', 'subtitle_job_id', 'generated_at'])->chunkById(200, function ($tracks) use ($patch): void {
                    foreach ($tracks as $track) {
                        $expiresAt = $patch['retentionDays'] === null ? null : $track->generated_at->addDays($patch['retentionDays']);
                        SubtitleJob::query()->whereKey($track->subtitle_job_id)->where('status', 'completed')->update(['expires_at' => $expiresAt]);
                        $track->update(['expires_at' => $expiresAt]);
                    }
                });
            }
            $settings->update(['values' => $values]);
        });

        return $this->summary();
    }

    public function requireGenerationKeys(string $selection): void
    {
        foreach (['elevenlabs', $selection] as $provider) {
            $this->requireProviderKey($provider);
        }
    }

    public function requireProviderKey(string $provider): void
    {
        if ($provider === 'codex') {
            app(CodexService::class)->requireConnected();

            return;
        }
        $this->apply();
        $provider = $provider === 'eleven' ? 'elevenlabs' : $provider;
        $paths = self::PROVIDERS[$provider] ?? null;
        if ($paths === null || blank(config($paths['key'])) || blank(config($paths['model']))) {
            throw new SubtitleProcessingException('provider_not_configured', 'Configure the '.$provider.' API key in Settings before generating.', 422);
        }
    }

    public function retentionDays(): ?int
    {
        return $this->stored()['retentionDays'] ?? null;
    }

    /** Acquire inside a transaction, before job or track locks. */
    public function lockForUpdate(): InstanceSetting
    {
        InstanceSetting::query()->firstOrCreate(['id' => 1], ['values' => []]);

        return InstanceSetting::query()->lockForUpdate()->findOrFail(1);
    }

    private function stored(): array
    {
        // The app also boots for installation and commands before migrations run.
        return Schema::hasTable('instance_settings')
            ? (InstanceSetting::query()->find(1)?->values ?? [])
            : [];
    }
}
