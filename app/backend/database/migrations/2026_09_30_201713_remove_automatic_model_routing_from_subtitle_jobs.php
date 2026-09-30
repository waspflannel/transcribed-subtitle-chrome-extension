<?php

use App\Models\InstanceSetting;
use App\Services\Audio\SubtitleAudioWorkspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('subtitle_jobs')->where('ai_provider', 'auto')->orderBy('id')->chunkById(100, function ($jobs): void {
            foreach ($jobs as $job) {
                $routing = json_decode($job->ai_routing ?? '{}', true);
                $model = $routing['configuration']['models']['openai'] ?? config('ai.providers.openai.models.text.default');
                $changes = [
                    'ai_provider' => 'openai',
                    'ai_model' => is_string($model) && trim($model) !== '' ? trim($model) : 'gpt-6-luna',
                    'run_id' => (string) Str::uuid(),
                ];
                if (in_array($job->status, ['queued', 'running'], true)) {
                    $changes += [
                        'status' => 'cancelled', 'error_code' => 'generation_cancelled',
                        'error_message' => 'Automatic model selection was removed. Choose OpenAI or Cerebras and generate again.',
                        'expires_at' => now()->addDays(30), 'updated_at' => now(),
                    ];
                }
                DB::table('subtitle_jobs')->where('id', $job->id)->update($changes);
                DB::table('subtitle_job_artifacts')->where('subtitle_job_id', $job->id)->delete();
                $oldRunId = $job->run_id;
                DB::afterCommit(static fn () => SubtitleAudioWorkspace::delete($oldRunId));
            }
        });

        // Manual and resolved automatic jobs now share compatibility. Keep every saved row.
        DB::table('subtitle_jobs')->update(['reuse_key' => null]);
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->dropColumn(['ai_selection_key', 'ai_routing']);
        });

        $settings = InstanceSetting::query()->find(1);
        if ($settings !== null) {
            $values = $settings->values;
            unset($values['providers']['typesafe']);
            $settings->update(['values' => $values]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subtitle_jobs', function (Blueprint $table): void {
            $table->string('ai_selection_key', 64)->default('manual');
            $table->json('ai_routing')->nullable();
        });
        DB::table('subtitle_jobs')->update(['reuse_key' => null]);
    }
};
