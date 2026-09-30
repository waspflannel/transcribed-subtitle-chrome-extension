<?php

namespace Tests\Feature;

use App\Ai\Agents\EditedCueAgent;
use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class KoreanQuickFixTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['안녕 하세요'])]
    #[TestWith(['원래 문장'])]
    public function test_preserves_korean_spaces_in_matched_and_reconstructed_quick_fixes(string $sourceText): void
    {
        $job = SubtitleJob::factory()->create([
            'status' => 'completed', 'stage' => 'finalizing', 'progress_percent' => 100,
            'source_language' => 'kor', 'detected_source_language' => 'kor',
            'include_translation' => false, 'include_romanization' => false,
        ]);
        $track = SubtitleTrack::factory()->create([
            'subtitle_job_id' => $job->id, 'youtube_video_id' => $job->youtube_video_id,
            'source_language' => 'kor', 'detected_source_language' => 'kor',
            'cues' => [[
                'cueId' => 'korean-cue', 'index' => 0, 'startMs' => 1200, 'endMs' => 4200,
                'sourceText' => $sourceText, 'translatedText' => $sourceText,
                'tokens' => [
                    ['index' => 0, 'text' => '안녕', 'normalizedText' => '안녕'],
                    ['index' => 1, 'text' => '하세요', 'normalizedText' => '하세요'],
                ],
            ]],
        ]);
        EditedCueAgent::fake(function (string $prompt): array {
            $cue = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR)['cues'][0];
            $this->assertSame('안녕 친구', $cue['sourceText']);

            return ['cues' => [[...$cue, 'tokens' => array_map(
                fn (array $token): array => [...$token, 'translation' => 'meaning', 'gloss' => 'meaning'],
                $cue['tokens'],
            )]]];
        })->preventStrayPrompts();

        $this->withExtensionInstall($job->install_id)
            ->patchJson('/v1/subtitle-jobs/'.$job->public_id.'/cues/korean-cue/tokens/1', [
                'expectedTrackId' => $track->public_id, 'text' => '친구',
            ])->assertOk()->assertJsonPath('cues.0.sourceText', '안녕 친구')
            ->assertJsonPath('cues.0.translatedText', '안녕 친구');

        $saved = $track->fresh();
        $this->assertNotSame($track->public_id, $saved->public_id);
        $this->assertSame(['안녕', '친구'], array_column($saved->cues[0]['tokens'], 'text'));
        $this->assertSame([1200, 4200], [$saved->cues[0]['startMs'], $saved->cues[0]['endMs']]);
        $this->assertStringContainsString('안녕 친구', $saved->web_vtt);
    }
}
