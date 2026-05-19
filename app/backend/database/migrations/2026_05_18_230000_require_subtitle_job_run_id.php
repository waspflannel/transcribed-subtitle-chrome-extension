<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subtitle_jobs')
            ->whereNull('run_id')
            ->orderBy('id')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    DB::table('subtitle_jobs')
                        ->where('id', $job->id)
                        ->update(['run_id' => (string) Str::uuid()]);
                }
            });

        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->uuid('run_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->uuid('run_id')->nullable()->change();
        });
    }
};
