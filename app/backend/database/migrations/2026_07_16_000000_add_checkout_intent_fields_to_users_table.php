<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('stripe_checkout_intent_id')->nullable()->after('stripe_subscription_item_id');
            $table->string('stripe_checkout_plan_code', 32)->nullable()->after('stripe_checkout_intent_id');
            $table->string('stripe_checkout_session_id')->nullable()->after('stripe_checkout_plan_code');
            $table->text('stripe_checkout_session_url')->nullable()->after('stripe_checkout_session_id');
            $table->timestamp('stripe_checkout_expires_at')->nullable()->index()->after('stripe_checkout_session_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_checkout_expires_at']);
            $table->dropColumn([
                'stripe_checkout_intent_id',
                'stripe_checkout_plan_code',
                'stripe_checkout_session_id',
                'stripe_checkout_session_url',
                'stripe_checkout_expires_at',
            ]);
        });
    }
};
