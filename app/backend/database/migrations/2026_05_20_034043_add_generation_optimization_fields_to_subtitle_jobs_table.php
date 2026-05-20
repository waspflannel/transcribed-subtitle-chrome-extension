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
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('generation_tier', 32)->default('base')->after('processing_version')->index();
            $table->unsignedBigInteger('estimated_provider_cost_microusd')->default(0)->after('progress_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn([
                'generation_tier',
                'estimated_provider_cost_microusd',
            ]);
        });
    }
};
