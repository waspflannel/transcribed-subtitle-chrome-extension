<?php

namespace Database\Factories;

use App\Ai\SubtitleModel;
use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubtitleJob>
 */
class SubtitleJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $videoId = Str::random(11);

        return [
            'public_id' => (string) Str::uuid(),
            'run_id' => (string) Str::uuid(),
            'youtube_video_id' => $videoId,
            'youtube_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'video_duration_seconds' => 213,
            'source_language' => 'auto',
            'detected_source_language' => null,
            'target_language' => 'eng',
            'processing_version' => SubtitleJobService::processingVersionFor(true, false),
            'ai_provider' => SubtitleModel::provider(),
            'ai_model' => SubtitleModel::model(),
            'include_romanization' => true,
            'include_translation' => false,
            'status' => 'running',
            'stage' => 'preparing',
            'progress_percent' => 5,
            'estimated_provider_cost_microusd' => 0,
            'install_id' => 'install_'.str_repeat('a', 32),
        ];
    }
}
