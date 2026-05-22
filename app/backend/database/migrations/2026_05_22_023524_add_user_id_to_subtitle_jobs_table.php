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
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            $table->dropUnique('subtitle_jobs_install_compatibility_unique');
            $table->unique(
                ['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_jobs_user_compatibility_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->unique(
                ['install_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_jobs_install_compatibility_unique',
            );
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
