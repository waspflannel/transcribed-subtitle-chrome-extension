<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_production_readiness_check_passes_for_safe_beta_configuration(): void
    {
        $this->configureSafeProductionRuntime();

        $this->assertSame(0, Artisan::call('ops:production-check', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertTrue($payload['ok']);
        $this->assertSame('https://api.example.test', $payload['summary']['appUrl']);
        $this->assertTrue($payload['summary']['openaiKeyConfigured']);
        $this->assertStringNotContainsString('sk-test-openai', $output);
        $this->assertStringNotContainsString('whsec_test', $output);
    }

    public function test_production_readiness_check_flags_unsafe_configuration_without_printing_secret_values(): void
    {
        $this->configureSafeProductionRuntime();
        config([
            'app.debug' => true,
            'app.url' => 'http://localhost',
            'ai.providers.openai.key' => '',
            'billing.testing_plan_switcher.enabled' => true,
            'queue.connections.redis.retry_after' => 60,
        ]);

        $this->assertSame(1, Artisan::call('ops:production-check', ['--json' => true]));

        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertFalse($payload['ok']);
        $this->assertContains('APP_DEBUG must be false.', $payload['problems']);
        $this->assertContains('APP_URL must use HTTPS.', $payload['problems']);
        $this->assertContains('OPENAI_API_KEY must be configured in the environment.', $payload['problems']);
        $this->assertContains('Queue retry_after must be greater than the subtitle worker timeout.', $payload['problems']);
        $this->assertStringNotContainsString('sk-test-stripe', $output);
    }

    private function configureSafeProductionRuntime(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:configured-app-key',
            'app.url' => 'https://api.example.test/v1',
            'database.default' => 'pgsql',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.retry_after' => 1260,
            'subtitles.queue.connection' => 'redis',
            'subtitles.queue.worker_timeout_seconds' => 1200,
            'subtitles.queue.auto_start.enabled' => false,
            'subtitles.tiers.concurrency_cache_store' => 'subtitle_concurrency',
            'cache.stores.subtitle_concurrency.driver' => 'redis',
            'logging.default' => 'stack',
            'logging.channels.single.level' => 'info',
            'logging.channels.stderr.level' => 'info',
            'ai.providers.openai.key' => 'sk-test-openai',
            'ai.providers.eleven.key' => 'elevenlabs-test-secret',
            'billing.stripe.secret' => 'sk-test-stripe',
            'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.testing_plan_switcher.enabled' => false,
            'billing.plans.base.stripe_price_id' => 'price_base',
            'billing.plans.plus.stripe_price_id' => 'price_plus',
            'billing.plans.pro.stripe_price_id' => 'price_pro',
            'subtitles.youtube.binary' => 'yt-dlp',
        ]);
    }
}
