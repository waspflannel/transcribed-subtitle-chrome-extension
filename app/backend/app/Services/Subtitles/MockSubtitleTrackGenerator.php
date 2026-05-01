<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use Illuminate\Support\Str;

class MockSubtitleTrackGenerator
{
    public function generate(SubtitleJob $job): SubtitleTrack
    {
        $generatedAt = now();

        return SubtitleTrack::create([
            'public_id' => (string) Str::uuid(),
            'subtitle_job_id' => $job->id,
            'youtube_video_id' => $job->youtube_video_id,
            'source_language' => $job->source_language,
            'target_language' => $job->target_language,
            'processing_version' => $job->processing_version,
            'detected_dialect_label' => 'Modern Standard Arabic',
            'detected_dialect_confidence' => 0.82,
            'generated_at' => $generatedAt,
            'expires_at' => $generatedAt->copy()->addDays(30),
            'cues' => $this->mockCues(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mockCues(): array
    {
        return [
            [
                'cueId' => 'cue-0001',
                'index' => 0,
                'startMs' => 1200,
                'endMs' => 4200,
                'sourceText' => "\u{0645}\u{0631}\u{062d}\u{0628}\u{0627} \u{0628}\u{0643}\u{0645}",
                'translatedText' => 'Welcome everyone',
                'romanization' => 'marhaban bikum',
                'tokens' => [
                    [
                        'index' => 0,
                        'text' => "\u{0645}\u{0631}\u{062d}\u{0628}\u{0627}",
                        'normalizedText' => "\u{0645}\u{0631}\u{062d}\u{0628}\u{0627}",
                        'lemma' => "\u{0645}\u{0631}\u{062d}\u{0628}\u{0627}",
                        'partOfSpeech' => 'interjection',
                        'translation' => 'hello',
                        'gloss' => 'greeting',
                        'romanization' => 'marhaban',
                    ],
                    [
                        'index' => 1,
                        'text' => "\u{0628}\u{0643}\u{0645}",
                        'normalizedText' => "\u{0628}\u{0643}\u{0645}",
                        'lemma' => "\u{0623}\u{0646}\u{062a}\u{0645}",
                        'partOfSpeech' => 'pronoun',
                        'translation' => 'you all',
                        'gloss' => 'with you',
                        'romanization' => 'bikum',
                    ],
                ],
            ],
        ];
    }
}
