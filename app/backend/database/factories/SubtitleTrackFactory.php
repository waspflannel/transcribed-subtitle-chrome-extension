<?php

namespace Database\Factories;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubtitleTrack>
 */
class SubtitleTrackFactory extends Factory
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
            'subtitle_job_id' => SubtitleJob::factory(),
            'youtube_video_id' => Str::random(11),
            'source_language' => 'ar',
            'target_language' => 'en',
            'processing_version' => SubtitleJobService::PROCESSING_VERSION,
            'generated_at' => now(),
            'expires_at' => now()->addDays(30),
            'cues' => [
                [
                    'cueId' => 'cue-0001',
                    'index' => 0,
                    'startMs' => 1200,
                    'endMs' => 4200,
                    'sourceText' => 'mock source text',
                    'translatedText' => 'Mock translation',
                    'romanization' => 'mock romanization',
                    'tokens' => [
                        [
                            'index' => 0,
                            'text' => 'mock',
                            'translation' => 'mock',
                        ],
                    ],
                ],
            ],
        ];
    }
}
