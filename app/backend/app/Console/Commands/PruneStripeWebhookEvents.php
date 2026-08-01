<?php

namespace App\Console\Commands;

use App\Models\StripeWebhookEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('billing:prune-webhook-events {--days=90 : Retain successfully processed events for this many days}')]
#[Description('Delete successfully processed Stripe webhook event records after the retention period.')]
class PruneStripeWebhookEvents extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = StripeWebhookEvent::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<=', $cutoff)
            ->delete();

        Log::info('backend.stripe_webhook_events_pruned', [
            'deleted_count' => $deleted,
            'retention_days' => $days,
        ]);
        $this->components->info("Pruned {$deleted} processed Stripe webhook event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
