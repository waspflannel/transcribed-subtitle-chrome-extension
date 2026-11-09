<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->boolean('use_creator_captions')->default(false);
            $table->string('transcript_source', 32)->default('scribe');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', fn (Blueprint $table) => $table->dropColumn(['use_creator_captions', 'transcript_source']));
    }
};
