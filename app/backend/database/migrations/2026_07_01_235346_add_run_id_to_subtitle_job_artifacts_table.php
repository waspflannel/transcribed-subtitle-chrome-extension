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
        Schema::table('subtitle_job_artifacts', function (Blueprint $table) {
            $table->uuid('run_id')->nullable()->after('batch_index');

            // Include run_id in the artifact unique key so a stale write from a
            // previous run can never overwrite or be confused for current-run
            // data even if it lands after a reset.
            $table->dropUnique('subtitle_job_artifacts_job_type_batch_unique');
            $table->unique(
                ['subtitle_job_id', 'artifact_type', 'batch_index', 'run_id'],
                'subtitle_job_artifacts_job_type_batch_run_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_job_artifacts', function (Blueprint $table) {
            $table->dropUnique('subtitle_job_artifacts_job_type_batch_run_unique');
            $table->unique(
                ['subtitle_job_id', 'artifact_type', 'batch_index'],
                'subtitle_job_artifacts_job_type_batch_unique',
            );
            $table->dropColumn('run_id');
        });
    }
};
