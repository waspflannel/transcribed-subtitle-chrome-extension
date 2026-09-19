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
        Schema::table('subtitle_jobs', function (Blueprint $table) {
            $table->string('ai_selection_key', 64)->default('manual');
            $table->json('ai_routing')->nullable();
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->unique(
                ['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version', 'transcription_options_hash', 'ai_selection_key', 'ai_provider', 'ai_model'],
                'subtitle_jobs_user_compatibility_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table) {
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->unique(
                ['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version', 'transcription_options_hash', 'ai_provider', 'ai_model'],
                'subtitle_jobs_user_compatibility_unique',
            );
            $table->dropColumn(['ai_selection_key', 'ai_routing']);
        });
    }
};
