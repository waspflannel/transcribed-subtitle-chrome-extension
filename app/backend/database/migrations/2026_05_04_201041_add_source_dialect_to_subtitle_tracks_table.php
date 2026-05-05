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
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->string('source_dialect', 64)->default('unknown')->after('target_language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->dropColumn('source_dialect');
        });
    }
};
