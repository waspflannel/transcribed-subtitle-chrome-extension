<?php

namespace Tests\Unit;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\LyricsCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LyricsCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalization_is_unicode_safe_and_removes_blank_lines(): void
    {
        $service = app(LyricsCorrectionService::class);

        $this->assertSame("Café déjà\nこんにちは", $service->normalizeLyrics("  Café   déjà\r\n\r\nこんにちは  "));
    }

    public function test_normalization_rejects_punctuation_and_emoji_only_input(): void
    {
        $this->expectExceptionMessage('Lyrics must contain at least one letter or number.');

        app(LyricsCorrectionService::class)->normalizeLyrics('!!! 😀');
    }

    public function test_correction_model_encrypts_lyrics(): void
    {
        $job = SubtitleJob::factory()->create(['status' => 'completed']);
        $track = SubtitleTrack::factory()->create(['subtitle_job_id' => $job->id]);
        $correction = $track->lyricsCorrection()->create([
            'attempt_id' => '018f9e2f-0d8c-7500-8f38-9f4c5d1b3020',
            'status' => 'queued',
            'lyrics' => 'private lyric text',
        ]);

        $this->assertSame('private lyric text', $correction->fresh()->lyrics);
        $this->assertStringNotContainsString('private lyric text', (string) $correction->getRawOriginal('lyrics'));
    }
}
