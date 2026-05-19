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
        Schema::table('subtitle_jobs', function (Blueprint $table) {
            $table->string('enrichment_mode', 16)->default('on_demand')->after('processing_version');
            $table->boolean('include_romanization')->default(true)->after('enrichment_mode');
            $table->boolean('include_translation')->default(false)->after('include_romanization');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'enrichment_mode',
                'include_romanization',
                'include_translation',
            ]);
        });
    }
};
