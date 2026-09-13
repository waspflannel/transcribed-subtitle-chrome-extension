<?php

namespace Tests\Unit;

use App\Services\Audio\ScribeAudioChunker;
use App\Services\Audio\TemporaryAudioFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScribeAudioChunkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'subtitles.transcription.chunking.first_seconds' => 0,
            'subtitles.transcription.chunking.second_seconds' => 0,
            'subtitles.transcription.chunking.min_audio_seconds' => 240,
            'subtitles.transcription.chunking.target_seconds' => 120,
            'subtitles.transcription.chunking.overlap_seconds' => 2.0,
            'subtitles.transcription.chunking.max_chunks' => 8,
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg-test',
        ]);
    }

    public function test_short_opening_chunk_stays_small_when_later_chunks_hit_the_upload_cap(): void
    {
        config(['subtitles.transcription.chunking.first_seconds' => 20]);
        $plan = $this->chunker()->plan(3600);
        $this->assertCount(8, $plan);
        $this->assertSame(20.0, $plan[0]['nominalEnd']);
        $this->assertSame(22.0, $plan[0]['audioEnd']);
        $this->assertSame(18.0, $plan[1]['audioStart']);
        $this->assertSame(3600.0, $plan[7]['nominalEnd']);
        foreach (array_slice($plan, 1) as $index => $chunk) {
            $this->assertSame($plan[$index]['nominalEnd'], $chunk['nominalStart']);
        }
    }

    public function test_short_videos_get_an_opening_chunk_without_exceeding_configured_limits(): void
    {
        config([
            'subtitles.transcription.chunking.first_seconds' => 20,
            'subtitles.transcription.chunking.min_audio_seconds' => 45,
            'subtitles.transcription.chunking.target_seconds' => 60,
        ]);
        $this->assertSame([], $this->chunker()->plan(44));
        $this->assertCount(2, $this->chunker()->plan(45));
        $this->assertSame(20.0, $this->chunker()->plan(45)[0]['nominalEnd']);
        config(['subtitles.transcription.chunking.max_chunks' => 1]);
        $this->assertSame([], $this->chunker()->plan(300));
    }

    public function test_short_audio_is_not_chunked(): void
    {
        $this->assertSame([], $this->chunker()->plan(239));
    }

    public function test_short_second_chunk_extends_the_opening_without_gaps_or_exceeding_upload_limits(): void
    {
        config([
            'subtitles.transcription.chunking.first_seconds' => 15,
            'subtitles.transcription.chunking.second_seconds' => 20,
            'subtitles.transcription.chunking.min_audio_seconds' => 45,
            'subtitles.transcription.chunking.target_seconds' => 60,
        ]);
        foreach ([45, 245, 3600] as $duration) {
            $plan = $this->chunker()->plan($duration);
            $this->assertLessThanOrEqual(8, count($plan));
            $this->assertSame(15.0, $plan[0]['nominalEnd']);
            $this->assertSame($duration === 45 ? 30.0 : 35.0, $plan[1]['nominalEnd']);
            $this->assertSame(13.0, $plan[1]['audioStart']);
            $this->assertSame((float) $duration, $plan[array_key_last($plan)]['nominalEnd']);
            foreach ($plan as $index => $chunk) {
                $this->assertGreaterThan($chunk['nominalStart'], $chunk['nominalEnd']);
                if ($index > 0) {
                    $this->assertSame($plan[$index - 1]['nominalEnd'], $chunk['nominalStart']);
                }
            }
        }
        config(['subtitles.transcription.chunking.max_chunks' => 2]);
        $plan = $this->chunker()->plan(245);
        $this->assertCount(2, $plan);
        $this->assertSame(245.0, $plan[1]['nominalEnd']);
        config(['subtitles.transcription.chunking.max_chunks' => 1]);
        $this->assertSame([], $this->chunker()->plan(245));
    }

    public function test_plan_splits_audio_evenly_with_symmetric_overlap(): void
    {
        $plan = $this->chunker()->plan(300);

        $this->assertSame([
            ['nominalStart' => 0.0, 'nominalEnd' => 100.0, 'audioStart' => 0.0, 'audioEnd' => 102.0],
            ['nominalStart' => 100.0, 'nominalEnd' => 200.0, 'audioStart' => 98.0, 'audioEnd' => 202.0],
            ['nominalStart' => 200.0, 'nominalEnd' => 300.0, 'audioStart' => 198.0, 'audioEnd' => 300.0],
        ], $plan);
    }

    public function test_plan_caps_chunk_count_for_very_long_audio(): void
    {
        // 3600s at a 120s target would be 30 concurrent uploads; the cap
        // grows chunks instead (8 x 450s).
        $plan = $this->chunker()->plan(3600);

        $this->assertCount(8, $plan);
        $this->assertSame(450.0, $plan[0]['nominalEnd']);
        $this->assertSame(3600.0, $plan[7]['nominalEnd']);
    }

    public function test_split_extracts_one_flac_per_plan_entry(): void
    {
        $directory = storage_path('framework/testing/scribe-audio-chunker/'.(string) Str::uuid());
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'scribe-ready.flac';
        File::put($path, 'prepared-flac');

        $audio = new TemporaryAudioFile(
            path: $path,
            directory: $directory,
            durationSeconds: 300,
            sizeBytes: File::size($path),
            mimeType: 'audio/flac',
        );

        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $this->assertIsArray($command);
            $this->assertLessThan(array_search('-i', $command, true), array_search('-ss', $command, true));
            File::put($command[array_key_last($command)], 'chunk-flac');

            return Process::result();
        });

        try {
            $chunker = $this->chunker();
            $chunks = [];
            foreach ($chunker->plan(300) as $index => $bounds) {
                $chunks[] = $chunker->extractChunk($audio, $index, $bounds['audioStart'], $bounds['audioEnd']);
            }

            $this->assertCount(3, $chunks);
            $this->assertSame([102, 104, 102], array_column($chunks, 'durationSeconds'));
            $this->assertSame('audio/flac', $chunks[0]->mimeType);

            Process::assertRan(function (PendingProcess $process) use ($path): bool {
                $command = $process->command;

                return is_array($command)
                    && $command[0] === 'ffmpeg-test'
                    && in_array('-ss', $command, true)
                    && in_array('98.000', $command, true)
                    && in_array('-t', $command, true)
                    && in_array('104.000', $command, true)
                    && in_array($path, $command, true);
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function chunker(): ScribeAudioChunker
    {
        return new ScribeAudioChunker;
    }

    public function test_raw_source_chunks_are_normalized_directly_to_mono_16khz_flac(): void
    {
        $directory = storage_path('framework/testing/scribe-audio-chunker/'.(string) Str::uuid());
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.'source.m4a';
        File::put($path, 'raw-source');
        $audio = new TemporaryAudioFile($path, $directory, 300, File::size($path), 'audio/mp4');
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($path) {
            $command = $process->command;
            $this->assertSame($path, $command[array_search('-i', $command, true) + 1]);
            $this->assertLessThan(array_search('-i', $command, true), array_search('-ss', $command, true));
            $this->assertSame('1', $command[array_search('-ac', $command, true) + 1]);
            $this->assertSame('16000', $command[array_search('-ar', $command, true) + 1]);
            $this->assertSame('flac', $command[array_search('-c:a', $command, true) + 1]);
            File::put($command[array_key_last($command)], 'chunk-flac');

            return Process::result();
        });

        try {
            $chunks = [];
            foreach ($this->chunker()->plan(300) as $index => $bounds) {
                $chunks[] = $this->chunker()->extractChunk($audio, $index, $bounds['audioStart'], $bounds['audioEnd']);
            }

            $this->assertCount(3, $chunks);
            $this->assertSame([102, 104, 102], array_column($chunks, 'durationSeconds'));
            $this->assertFileDoesNotExist($directory.DIRECTORY_SEPARATOR.'scribe-ready.flac');
            Process::assertRanTimes(fn (): bool => true, 3);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
