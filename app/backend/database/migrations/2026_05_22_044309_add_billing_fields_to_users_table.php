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
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique()->after('remember_token');
            $table->string('stripe_subscription_id')->nullable()->unique()->after('stripe_customer_id');
            $table->string('stripe_subscription_item_id')->nullable()->after('stripe_subscription_id');
            $table->string('billing_plan_code', 32)->nullable()->index()->after('stripe_subscription_item_id');
            $table->string('billing_subscription_status', 32)->nullable()->index()->after('billing_plan_code');
            $table->timestamp('billing_current_period_start')->nullable()->after('billing_subscription_status');
            $table->timestamp('billing_current_period_end')->nullable()->index()->after('billing_current_period_start');
            $table->boolean('billing_cancel_at_period_end')->default(false)->after('billing_current_period_end');
            $table->timestamp('billing_trial_ends_at')->nullable()->after('billing_cancel_at_period_end');
            $table->timestamp('billing_ends_at')->nullable()->after('billing_trial_ends_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropUnique(['stripe_subscription_id']);
            $table->dropIndex(['billing_plan_code']);
            $table->dropIndex(['billing_subscription_status']);
            $table->dropIndex(['billing_current_period_end']);
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_subscription_item_id',
                'billing_plan_code',
                'billing_subscription_status',
                'billing_current_period_start',
                'billing_current_period_end',
                'billing_cancel_at_period_end',
                'billing_trial_ends_at',
                'billing_ends_at',
            ]);
        });
    }
};
