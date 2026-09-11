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
            // Older jobs had no provider history; pin their deployment default once.
            $provider = config('ai.default', 'openai');
            $model = config("ai.providers.{$provider}.models.text.default");
            $table->string('ai_provider', 32)->default($provider);
            $table->string('ai_model', 128)->default($model);
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->unique(
                ['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version', 'transcription_options_hash', 'ai_provider', 'ai_model'],
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
                ['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version', 'transcription_options_hash'],
                'subtitle_jobs_user_compatibility_unique',
            );
            $table->dropColumn(['ai_provider', 'ai_model']);
        });
    }
};
