<?php

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleJobLock;
use App\Services\Subtitles\SubtitleJobService;
use App\Services\Transcription\ElevenLabsScribeTranscriptionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $directory, $jobId, $label, $mode] = $argv;
$connection = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
if ($connection['host'] !== '127.0.0.1'
    || ! preg_match('/^subtitle_review_test_[a-z0-9]+$/', $connection['database'])
    || ! preg_match('/^paid_work_test_[a-f0-9]+$/', $connection['search_path'])) {
    throw new RuntimeException('Worker requires an isolated test database and schema.');
}
$connection['application_name'] = $connection['search_path'].'_'.$label;
config([
    'database.connections.paid_work_test' => $connection, 'database.default' => 'paid_work_test',
    'ai.providers.eleven.key' => 'fake-key', 'ai.providers.eleven.url' => 'https://api.elevenlabs.test/v1',
    'ai.providers.eleven.models.transcription.default' => 'scribe_v2',
]);
Bus::fake();
Http::preventStrayRequests();
$job = SubtitleJob::findOrFail($jobId);
$pause = function (string $phase) use ($directory, $label): void {
    touch($directory.'/'.$label.'-'.$phase.'-paused');
    $deadline = microtime(true) + 10;
    while (! is_file($directory.'/'.$label.'-'.$phase.'-release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker barrier timed out.');
        }
        usleep(20_000);
    }
};

if (str_starts_with($mode, 'request')) {
    if ($mode === 'request-paused') {
        SubtitleJob::saving(function (SubtitleJob $current) use ($pause): void {
            if ($current->isDirty('paid_work_started_at')) {
                $pause('marker');
            }
        });
    }
    Http::fake(function () use ($directory, $mode, $pause) {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Paid request began before the dispatch marker committed.');
        }
        file_put_contents($directory.'/provider-requests', "request\n", FILE_APPEND | LOCK_EX);
        if ($mode === 'request-paused') {
            $pause('request');
        }

        return Http::response(['words' => [['text' => 'hello', 'type' => 'word', 'start' => 0, 'end' => 1]]]);
    });
    try {
        app(ElevenLabsScribeTranscriptionService::class)->transcribeYouTube($job->youtube_video_id, 'eng', $job);
        echo "request-completed\n";
    } catch (SubtitleProcessingException $exception) {
        if ($exception->publicCode !== 'generation_cancelled') {
            throw $exception;
        }
        echo "request-fenced\n";
    }
} else {
    $settle = function () use ($job, $mode): void {
        if (str_starts_with($mode, 'delete')) {
            app(SubtitleJobService::class)->delete($job);
        } else {
            app(SubtitleJobService::class)->cancel($job, $job->user);
        }
    };
    if (str_ends_with($mode, '-paused')) {
        DB::transaction(function () use ($job, $settle, $pause): void {
            SubtitleJobLock::current($job->id, $job->run_id);
            $settle();
            $pause('settlement');
        });
    } else {
        $settle();
    }
    echo "settled\n";
}
