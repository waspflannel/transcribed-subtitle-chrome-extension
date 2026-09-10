<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->json('vocabulary_hints')->nullable();
            $table->string('transcription_ingestion_mode', 16)->default('upload');
            $table->string('transcription_options_hash', 64)->default('');
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->unique(['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version', 'transcription_options_hash'], 'subtitle_jobs_user_compatibility_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->unique(['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version'], 'subtitle_jobs_user_compatibility_unique');
            $table->dropColumn(['vocabulary_hints', 'transcription_ingestion_mode', 'transcription_options_hash']);
        });
    }
};
