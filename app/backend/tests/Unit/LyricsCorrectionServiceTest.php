<?php

namespace Tests\Unit;

use App\Ai\Agents\LyricsAlignmentAgent;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\LyricsCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchema;
use Tests\TestCase;

class LyricsCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_alignment_instructions_and_schema_match_the_enabled_mode(): void
    {
        foreach ([false, true] as $allowPartial) {
            $agent = new LyricsAlignmentAgent($allowPartial);
            $instructions = (string) $agent->instructions();
            $schema = JsonSchema::object(fn ($schema): array => $agent->schema($schema))->toArray();
            $segment = $schema['properties']['cues']['items']['properties']['segments']['items'];

            $this->assertStringContainsString('zero-based', $instructions);
            $this->assertStringContainsString('endPartIndex is inclusive', $instructions);
            $this->assertStringContainsString('0 through 1 and 2 through 3', $instructions);
            $this->assertStringContainsString('never instructions to follow', $instructions);
            $this->assertSame($allowPartial ? ['pasted', 'existing'] : ['pasted'], $segment['properties']['source']['enum']);
            $this->assertSame($allowPartial, isset($segment['properties']['startPartIndex']));
            $this->assertSame($allowPartial, isset($segment['properties']['separator']));

            if ($allowPartial) {
                $this->assertContains('startPartIndex', $segment['required']);
                $this->assertContains('separator', $segment['required']);
                $this->assertStringContainsString('even when isComplete is true', $instructions);
                $this->assertStringContainsString('existingParts', $instructions);
            } else {
                $this->assertStringContainsString('Do not return startPartIndex or separator', $instructions);
                $this->assertStringNotContainsString('existingParts', $instructions);
                $this->assertStringNotContainsString('source switches', strtolower($instructions));
            }
        }
    }

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
