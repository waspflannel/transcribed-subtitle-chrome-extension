<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subtitle_jobs')
            ->whereNull('youtube_url')
            ->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    DB::table('subtitle_jobs')
                        ->where('id', $job->id)
                        ->update(['youtube_url' => 'https://www.youtube.com/watch?v='.$job->youtube_video_id]);
                }
            });

        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('youtube_url')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('youtube_url')->nullable()->change();
        });
    }
};
