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
            $table->dropColumn(['billing_trial_ends_at', 'billing_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Restores the unused columns, not any previously stored values.
            $table->timestamp('billing_trial_ends_at')->nullable();
            $table->timestamp('billing_ends_at')->nullable();
        });
    }
};
