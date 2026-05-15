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
        Schema::create('subtitle_job_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subtitle_job_id')->constrained()->cascadeOnDelete();
            $table->string('artifact_type', 64);
            $table->unsignedInteger('batch_index')->default(0);
            $table->json('payload');
            $table->timestamps();

            $table->unique(
                ['subtitle_job_id', 'artifact_type', 'batch_index'],
                'subtitle_job_artifacts_job_type_batch_unique',
            );
            $table->index(['subtitle_job_id', 'artifact_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subtitle_job_artifacts');
    }
};
