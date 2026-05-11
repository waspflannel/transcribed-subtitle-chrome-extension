<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('detected_source_language', 8)->nullable()->after('source_language');
        });

        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->string('detected_source_language', 8)->nullable()->after('source_language');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->dropColumn('detected_source_language');
        });

        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn('detected_source_language');
        });
    }
};
