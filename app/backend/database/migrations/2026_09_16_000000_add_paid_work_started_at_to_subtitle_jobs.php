<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->timestampTz('paid_work_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn('paid_work_started_at');
        });
    }
};
