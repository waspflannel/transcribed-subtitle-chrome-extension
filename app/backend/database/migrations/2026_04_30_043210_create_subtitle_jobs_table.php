<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('youtube_video_id', 11)->index();
            $table->string('youtube_url')->nullable();
            $table->unsignedInteger('video_duration_seconds')->nullable();
            $table->string('source_language', 8);
            $table->string('target_language', 8);
            $table->json('options');
            $table->string('processing_version', 64);
            $table->string('status', 32)->default('queued')->index();
            $table->string('progress_stage', 32)->nullable();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->string('progress_message')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message')->nullable();
            $table->json('error_details')->nullable();
            $table->string('install_id', 128)->index();
            $table->string('request_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['youtube_video_id', 'source_language', 'target_language', 'processing_version'],
                'subtitle_jobs_compatibility_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_jobs');
    }
};
