<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('status', 16)->default('running')->after('processing_version')->index();
            $table->string('stage', 32)->nullable()->after('status');
            $table->unsignedTinyInteger('progress_percent')->nullable()->after('stage');
            $table->string('error_code', 64)->nullable()->after('progress_percent');
            $table->string('error_message')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn([
                'status',
                'stage',
                'progress_percent',
                'error_code',
                'error_message',
            ]);
        });
    }
};
