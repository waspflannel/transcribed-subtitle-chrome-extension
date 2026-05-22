<?php

namespace App\Console\Commands;

use App\Models\BillingUsageEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:usage-report {--json : Emit JSON instead of a table}')]
#[Description('Show public minute usage and provider-cost telemetry by billing plan.')]
class ShowBillingUsageReport extends Command
{
    public function handle(): int
    {
        $rows = BillingUsageEvent::query()
            ->select('plan_code')
            ->selectRaw('sum(available_minutes_delta) as available_delta')
            ->selectRaw('sum(reserved_minutes_delta) as reserved_minutes')
            ->selectRaw('sum(used_minutes_delta) as used_minutes')
            ->selectRaw('sum(provider_cost_microusd_delta) as provider_cost_microusd')
            ->groupBy('plan_code')
            ->orderBy('plan_code')
            ->get()
            ->map(fn (BillingUsageEvent $event): array => [
                'plan' => $event->plan_code,
                'available_delta' => (int) $event->available_delta,
                'reserved_minutes' => (int) $event->reserved_minutes,
                'used_minutes' => (int) $event->used_minutes,
                'provider_cost_microusd' => (int) $event->provider_cost_microusd,
                'provider_cost_per_used_minute_microusd' => (int) $event->used_minutes > 0
                    ? (int) round(((int) $event->provider_cost_microusd) / (int) $event->used_minutes)
                    : 0,
            ])
            ->values();

        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Plan', 'Available Delta', 'Reserved', 'Used', 'Provider Cost Microusd', 'Cost / Used Minute'],
            $rows->map(fn (array $row): array => [
                $row['plan'],
                $row['available_delta'],
                $row['reserved_minutes'],
                $row['used_minutes'],
                $row['provider_cost_microusd'],
                $row['provider_cost_per_used_minute_microusd'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
