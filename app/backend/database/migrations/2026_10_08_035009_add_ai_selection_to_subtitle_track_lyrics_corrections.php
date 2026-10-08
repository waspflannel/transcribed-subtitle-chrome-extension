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
        Schema::table('subtitle_track_lyrics_corrections', function (Blueprint $table): void {
            $table->string('ai_provider', 20)->nullable();
            $table->string('ai_model', 128)->nullable();
            $table->boolean('ai_fast_mode')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_track_lyrics_corrections', function (Blueprint $table): void {
            $table->dropColumn(['ai_provider', 'ai_model', 'ai_fast_mode']);
        });
    }
};
