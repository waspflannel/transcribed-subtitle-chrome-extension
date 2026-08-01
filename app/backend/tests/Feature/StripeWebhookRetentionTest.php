<?php

namespace Tests\Feature;

use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StripeWebhookRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_keeps_recent_and_failed_webhook_events_for_diagnostics(): void
    {
        $expired = StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_expired',
            'type' => 'customer.subscription.updated',
            'livemode' => false,
            'payload_hash' => hash('sha256', 'expired'),
            'processed_at' => now()->subDays(91),
        ]);
        $recent = StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_recent',
            'type' => 'customer.subscription.updated',
            'livemode' => false,
            'payload_hash' => hash('sha256', 'recent'),
            'processed_at' => now()->subDays(89),
        ]);
        $failed = StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_failed',
            'type' => 'customer.subscription.updated',
            'livemode' => false,
            'payload_hash' => hash('sha256', 'failed'),
            'processing_error' => 'retry needed',
        ]);

        $this->assertSame(0, Artisan::call('billing:prune-webhook-events'));

        $this->assertModelMissing($expired);
        $this->assertModelExists($recent);
        $this->assertModelExists($failed);
    }
}
