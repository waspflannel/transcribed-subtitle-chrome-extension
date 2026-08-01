<?php

namespace Tests\Unit;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\Subtitles\TimestampedSubtitleTrackGenerator;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\Transcription\TimestampedTranscriptSegment;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimestampedSubtitleTrackGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_validated_enriched_cues_and_persists_webvtt(): void
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

        $this->assertSame($this->sampleWebVtt(), $track->web_vtt);
        $this->assertCount(3, $track->cues);
        $this->assertSame([
            'cueId' => 'cue-0001',
            'index' => 0,
            'startMs' => 0,
            'endMs' => 800,
            'sourceText' => 'first segment',
            'translatedText' => 'Translation 1',
            'tokens' => [
                [
                    'index' => 0,
                    'text' => 'first',
                    'normalizedText' => 'first',
                    'gloss' => 'first',
                ],
            ],
        ], $track->cues[0]);
        $this->assertSame('cue-0002', $track->cues[1]['cueId']);
        $this->assertSame('second segment', $track->cues[1]['sourceText']);
        $this->assertSame('third segment', $track->cues[2]['sourceText']);
        $this->assertTrue($track->expires_at->isSameSecond(Carbon::parse('2026-06-01 12:00:00')));
    }

    public function test_rejects_transcripts_without_webvtt(): void
    {
        $this->assertInvalidTranscriptReason(
            'invalid_web_vtt',
            [new TimestampedTranscriptSegment(0.0, 2.0, 'source text')],
            '',
        );
    }

    public function test_rejects_enriched_cues_without_tokens(): void
    {
        $generator = app(TimestampedSubtitleTrackGenerator::class);
        $job = SubtitleJob::factory()->create();
        $transcript = new TimestampedTranscript(
            language: 'spa',
            durationSeconds: 2.0,
            segments: [new TimestampedTranscriptSegment(0.0, 2.0, 'Hola a todos')],
            webVtt: "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHola a todos\n",
        );
        $draftCues = $generator->draftCues($transcript);
        $enrichedCues = [[
            ...$draftCues[0],
            'translatedText' => 'Hola a todos',
            'tokens' => [],
        ]];

        try {
            $generator->generate($job, $transcript, new CueEnrichmentResult($enrichedCues, 'unknown'));
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('enrichment_failed', $exception->publicCode);
            $this->assertSame('empty_tokens', $exception->context['reason'] ?? null);

            return;
        }

        $this->fail('Expected empty enriched token output to fail.');
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
    private function generateTrack(array $segments, ?string $webVtt = null): SubtitleTrack
    {
        $job = SubtitleJob::factory()->create();
        $generator = app(TimestampedSubtitleTrackGenerator::class);
        $transcript = new TimestampedTranscript(
            language: 'spa',
            durationSeconds: 8.0,
            segments: $segments,
            webVtt: $webVtt ?? $this->sampleWebVtt(),
        );
        $draftCues = $generator->draftCues($transcript);
        $enrichedCues = array_map(
            function (array $cue): array {
                $tokenText = explode(' ', $cue['sourceText'])[0] ?: $cue['sourceText'];

                return [
                    ...$cue,
                    'translatedText' => 'Translation '.($cue['index'] + 1),
                    'tokens' => [
                        [
                            'index' => 0,
                            'text' => $tokenText,
                            'normalizedText' => strtolower($tokenText),
                            'gloss' => $tokenText,
                        ],
                    ],
                ];
            },
            $draftCues,
        );

        return $generator->generate(
            $job,
            $transcript,
            new CueEnrichmentResult($enrichedCues, 'unknown'),
        );
    }

    /**
     * @param  array<int, TimestampedTranscriptSegment>  $segments
     */
    private function assertInvalidTranscriptReason(string $reason, array $segments, ?string $webVtt = null): void
    {
        try {
            $this->generateTrack($segments, $webVtt);
        } catch (SubtitleProcessingException $exception) {
            $this->assertSame('transcription_failed', $exception->publicCode);
            $this->assertSame($reason, $exception->context['reason'] ?? null);

            return;
        }

        $this->fail('Expected subtitle cue validation to fail.');
    }

    private function sampleWebVtt(): string
    {
        return "WEBVTT\n\n00:00:00.000 --> 00:00:00.800\nfirst segment\n\n00:00:00.850 --> 00:00:02.000\nsecond segment\n\n00:00:03.000 --> 00:00:05.000\nthird segment\n";
    }
}
