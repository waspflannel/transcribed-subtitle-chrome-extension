<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn('vocabulary_hints');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->json('vocabulary_hints')->nullable();
        });
    }
};
