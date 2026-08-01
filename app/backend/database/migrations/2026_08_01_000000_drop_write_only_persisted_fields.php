<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn('request_ip');
        });

        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->dropColumn('source_dialect');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['billing_trial_ends_at', 'billing_ends_at']);
        });

        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->dropColumn('minutes');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('request_ip', 45)->nullable();
        });

        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->string('source_dialect', 64)->default('unknown');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('billing_trial_ends_at')->nullable();
            $table->timestamp('billing_ends_at')->nullable();
        });

        Schema::table('billing_usage_events', function (Blueprint $table): void {
            $table->unsignedInteger('minutes');
        });
    }
};
