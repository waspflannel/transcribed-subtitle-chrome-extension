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
        Schema::create('cached_video_transcripts', function (Blueprint $table) {
            $table->id();
            $table->string('youtube_video_id', 16);
            $table->string('requested_source_language', 8);
            $table->string('transcription_model', 64);
            $table->unsignedInteger('audio_duration_seconds');
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['youtube_video_id', 'requested_source_language', 'transcription_model'],
                'cached_video_transcripts_key_unique',
            );
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cached_video_transcripts');
    }
};
