<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropUnique('subtitle_jobs_compatibility_unique');
            $table->unique(
                ['install_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_jobs_install_compatibility_unique',
            );
        });

        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->dropUnique('subtitle_tracks_compatibility_unique');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->unique(
                ['youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_tracks_compatibility_unique',
            );
        });

        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropUnique('subtitle_jobs_install_compatibility_unique');
            $table->unique(
                ['youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_jobs_compatibility_unique',
            );
        });
    }
};
