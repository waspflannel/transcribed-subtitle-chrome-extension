<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_track_lyrics_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subtitle_track_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('attempt_id')->unique();
            $table->string('status', 16)->index();
            $table->text('lyrics')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_track_lyrics_corrections');
    }
};
