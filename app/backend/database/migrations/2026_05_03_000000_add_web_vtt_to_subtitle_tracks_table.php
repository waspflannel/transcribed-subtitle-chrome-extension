<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->text('web_vtt')->nullable()->after('cues');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->dropColumn('web_vtt');
        });
    }
};
