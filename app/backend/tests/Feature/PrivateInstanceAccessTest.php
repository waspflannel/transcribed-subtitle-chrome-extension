<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateInstanceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_extension_can_read_settings_without_an_account(): void
    {
        $this->withHeader('Origin', 'chrome-extension://'.str_repeat('a', 32))
            ->withExtensionInstall('install_'.str_repeat('a', 32))
            ->getJson('/v1/settings')->assertOk()->assertJsonStructure(['providers', 'retentionDays']);
    }

    public function test_public_clients_cannot_read_or_replace_instance_settings(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->withExtensionInstall('install_'.str_repeat('a', 32))
            ->getJson('/v1/settings')->assertForbidden()->assertJsonPath('error.code', 'instance_access_denied');
        $this->putJson('/v1/settings', ['retentionDays' => 1])->assertForbidden();
        $this->assertDatabaseCount('instance_settings', 0);
    }

    public function test_cross_site_origins_and_rebound_hosts_are_rejected(): void
    {
        $this->withExtensionInstall('install_'.str_repeat('a', 32))
            ->withHeader('Origin', 'https://untrusted.example')
            ->putJson('/v1/settings', ['retentionDays' => 1])->assertForbidden();
        $this->withoutHeader('Origin')->getJson('http://untrusted.example/v1/settings')->assertForbidden();
        $this->assertDatabaseCount('instance_settings', 0);
    }

    public function test_self_hosted_operator_can_allow_a_private_network(): void
    {
        config(['instance.allowed_networks' => ['10.10.0.0/24'], 'app.url' => 'https://subtitles.internal']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.8'])
            ->withExtensionInstall('install_'.str_repeat('a', 32))
            ->getJson('https://subtitles.internal/v1/settings')->assertOk();
    }

    public function test_forwarded_headers_do_not_allow_public_clients_to_impersonate_loopback(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->withHeader('X-Forwarded-For', '127.0.0.1')
            ->withExtensionInstall('install_'.str_repeat('a', 32))
            ->getJson('/v1/settings')->assertForbidden();
    }
}
