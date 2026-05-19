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
        Schema::create('subtitle_job_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subtitle_job_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('public_job_id')->nullable()->index();
            $table->uuid('run_id')->nullable()->index();
            $table->string('event', 96)->index();
            $table->string('stage', 64)->nullable()->index();
            $table->string('status', 32)->nullable();
            $table->string('queue_connection', 64)->nullable();
            $table->string('queue', 128)->nullable();
            $table->string('laravel_job_uuid', 64)->nullable()->index();
            $table->string('laravel_batch_id', 64)->nullable()->index();
            $table->unsignedInteger('batch_index')->nullable();
            $table->unsignedInteger('worker_pid')->nullable();
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('wait_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('exception', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['subtitle_job_id', 'run_id', 'created_at'], 'subtitle_job_events_job_run_created_index');
            $table->index(['event', 'created_at'], 'subtitle_job_events_event_created_index');
            $table->index(['stage', 'created_at'], 'subtitle_job_events_stage_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subtitle_job_events');
    }
};
