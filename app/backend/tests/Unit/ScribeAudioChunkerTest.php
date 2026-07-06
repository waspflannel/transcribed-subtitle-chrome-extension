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
            'subtitles.transcription.chunking.min_audio_seconds' => 240,
            'subtitles.transcription.chunking.target_seconds' => 120,
            'subtitles.transcription.chunking.overlap_seconds' => 2.0,
            'subtitles.transcription.chunking.max_chunks' => 8,
            'subtitles.audio_preparation.ffmpeg_binary' => 'ffmpeg-test',
        ]);
    }

    public function test_short_audio_is_not_chunked(): void
    {
        $this->assertSame([], $this->chunker()->plan(239));
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
            File::put($command[array_key_last($command)], 'chunk-flac');

            return Process::result();
        });

        try {
            $chunker = $this->chunker();
            $chunks = $chunker->split($audio, $chunker->plan(300));

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
}
