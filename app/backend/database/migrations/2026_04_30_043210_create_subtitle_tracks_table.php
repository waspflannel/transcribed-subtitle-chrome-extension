<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_tracks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('subtitle_job_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id', 11)->index();
            $table->string('source_language', 8);
            $table->string('target_language', 8);
            $table->string('processing_version', 64);
            $table->timestamp('generated_at')->index();
            $table->timestamp('expires_at')->index();
            $table->json('cues');
            $table->timestamps();

            $table->unique(
                ['youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_tracks_compatibility_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_tracks');
    }
};
