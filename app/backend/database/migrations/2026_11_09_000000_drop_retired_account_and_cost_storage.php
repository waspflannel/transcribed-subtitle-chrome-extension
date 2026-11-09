<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['billing_usage_events', 'stripe_webhook_events', 'personal_access_tokens', 'password_reset_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('subtitle_jobs', fn (Blueprint $table) => $table->dropColumn('estimated_provider_cost_microusd'));
    }

    public function down(): void
    {
        throw new LogicException('Restore a database backup to recover retired account and cost records.');
    }
};
