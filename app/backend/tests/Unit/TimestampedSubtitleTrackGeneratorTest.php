<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\TimestampedSubtitleTrackGenerator;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimestampedSubtitleTrackGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_validated_source_only_cues_from_timestamped_transcript(): void
    {
        Carbon::setTestNow('2026-05-02 12:00:00');

        try {
            $track = $this->generateTrack([
                new TimestampedTranscriptSegment(0.0, 0.8, ' first  segment '),
                new TimestampedTranscriptSegment(0.85, 2.0, 'second segment'),
                new TimestampedTranscriptSegment(3.0, 5.0, 'third segment'),
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertCount(2, $track->cues);
        $this->assertSame([
            'cueId' => 'cue-0001',
            'index' => 0,
            'startMs' => 0,
            'endMs' => 2000,
            'sourceText' => 'first segment second segment',
            'translatedText' => 'first segment second segment',
            'tokens' => [],
        ], $track->cues[0]);
        $this->assertSame('cue-0002', $track->cues[1]['cueId']);
        $this->assertSame('third segment', $track->cues[1]['sourceText']);
        $this->assertTrue($track->expires_at->isSameSecond(Carbon::parse('2026-06-01 12:00:00')));
    }

    public function test_splits_overly_long_cues_when_word_boundaries_are_available(): void
    {
        $track = $this->generateTrack([
            new TimestampedTranscriptSegment(
                0.0,
                12.0,
                implode(' ', array_fill(0, 45, 'word')),
            ),
        ]);

        $this->assertCount(2, $track->cues);
        $this->assertSame(0, $track->cues[0]['startMs']);
        $this->assertSame(6000, $track->cues[0]['endMs']);
        $this->assertSame(6000, $track->cues[1]['startMs']);
        $this->assertSame(12000, $track->cues[1]['endMs']);
        $this->assertLessThanOrEqual(180, mb_strlen($track->cues[0]['sourceText']));
        $this->assertLessThanOrEqual(180, mb_strlen($track->cues[1]['sourceText']));
    }

    public function test_rejects_empty_source_text(): void
    {
        $this->assertInvalidTranscriptReason('empty_source_text', [
            new TimestampedTranscriptSegment(0.0, 2.0, '   '),
        ]);
    }

    public function test_rejects_overlapping_timing(): void
    {
        $this->assertInvalidTranscriptReason('overlapping_timing', [
            new TimestampedTranscriptSegment(0.0, 2.0, 'first segment'),
            new TimestampedTranscriptSegment(1.5, 3.0, 'overlapping segment'),
        ]);
    }

    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    private function generateTrack(array $segments): SubtitleTrack
    {
        $job = SubtitleJob::factory()->create();

        return app(TimestampedSubtitleTrackGenerator::class)->generate(
            $job,
            new TimestampedTranscript(
                language: 'ar',
                durationSeconds: 8.0,
                segments: $segments,
            ),
        );
    }

    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    private function assertInvalidTranscriptReason(string $reason, array $segments): void
    {
        try {
            $this->generateTrack($segments);
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame($reason, $exception->context['reason'] ?? null);

            return;
        }

        $this->fail('Expected subtitle cue validation to fail.');
    }
}
