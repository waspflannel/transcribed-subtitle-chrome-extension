<?php

namespace Database\Factories;

use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleJobService;
use App\SubtitleJobStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubtitleJob>
 */
class SubtitleJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'youtube_video_id' => Str::random(11),
            'youtube_url' => null,
            'video_duration_seconds' => 213,
            'source_language' => 'ar',
            'target_language' => 'en',
            'options' => [
                'includeRomanization' => true,
                'includeGloss' => true,
            ],
            'processing_version' => SubtitleJobService::PROCESSING_VERSION,
            'status' => SubtitleJobStatus::Queued,
            'progress_stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => 'Queued',
            'install_id' => 'install_'.str_repeat('a', 32),
            'request_ip' => '127.0.0.1',
        ];
    }
}
