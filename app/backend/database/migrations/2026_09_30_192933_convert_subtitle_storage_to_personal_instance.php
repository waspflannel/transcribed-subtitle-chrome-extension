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
        // Old tier queues are retired. Preserve interrupted jobs for an explicit retry.
        DB::table('subtitle_jobs')->whereIn('status', ['queued', 'running'])->orderBy('id')->chunkById(100, function ($jobs): void {
            foreach ($jobs as $job) {
                DB::table('subtitle_jobs')->where('id', $job->id)->update([
                    'status' => 'cancelled', 'run_id' => (string) Str::uuid(),
                    'error_code' => 'generation_cancelled',
                    'error_message' => 'Generation was interrupted by the BYOK upgrade. Generate again to retry.',
                    'expires_at' => now()->addDays(30), 'updated_at' => now(),
                ]);
            }
        });

        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropUnique('subtitle_jobs_user_compatibility_unique');
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['generation_tier']);
            $table->dropColumn(['generation_tier', 'paid_work_started_at']);
            // Historical tracks stay intact even if several accounts saved the same video.
            $table->char('reuse_key', 64)->nullable()->unique();
        });
        Schema::table('subtitle_tracks', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->change();
        });
        DB::table('subtitle_tracks')->update(['expires_at' => null]);
        DB::table('subtitle_jobs')->where('status', 'completed')->update(['expires_at' => null]);
    }

    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropUnique(['reuse_key']);
            $table->dropColumn('reuse_key');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('generation_tier', 32)->default('base')->index();
            $table->timestampTz('paid_work_started_at')->nullable();
            $table->unique(['user_id', 'youtube_video_id', 'source_language', 'target_language', 'processing_version', 'transcription_options_hash', 'ai_selection_key', 'ai_provider', 'ai_model'], 'subtitle_jobs_user_compatibility_unique');
        });
        // Keep nullable expiry: making saved tracks expire during rollback would lose data.
    }
};
