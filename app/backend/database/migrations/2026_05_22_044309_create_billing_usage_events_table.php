<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subtitle_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subtitle_track_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('plan_code', 32)->index();
            $table->string('event_type', 32)->index();
            $table->timestamp('billing_period_start')->index();
            $table->timestamp('billing_period_end')->index();
            $table->unsignedInteger('minutes');
            $table->integer('available_minutes_delta')->default(0);
            $table->integer('reserved_minutes_delta')->default(0);
            $table->integer('used_minutes_delta')->default(0);
            $table->bigInteger('provider_cost_microusd_delta')->default(0);
            $table->string('idempotency_key')->unique();
            $table->string('created_by')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'billing_period_start', 'billing_period_end']);
            $table->index(['subtitle_job_id', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_usage_events');
    }
};
