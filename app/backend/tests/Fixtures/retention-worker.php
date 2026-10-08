<?php

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\InstanceSettings;
use App\Services\Subtitles\SubtitleGenerationPipeline;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if ($connection['host'] !== '127.0.0.1' || $connection['port'] <= 1024
    || ! preg_match('/^subtitle_review_test_[a-z0-9]+$/', $connection['database'])
    || ! preg_match('/^byok_retention_[a-f0-9]+$/', $connection['search_path'])) {
    throw new RuntimeException('Unsafe retention test connection.');
}
config(['database.connections.retention_test' => $connection]);
DB::setDefaultConnection('retention_test');
Http::preventStrayRequests();
$action = $argv[2];
$jobId = (int) $argv[3];
$releasePath = $argv[4];
$retentionDays = json_decode($argv[5], true, flags: JSON_THROW_ON_ERROR);
DB::select("select set_config('application_name', ?, false)", [$connection['search_path'].'_'.$action]);
$pause = function () use ($releasePath): void {
    echo "HELD\n";
    flush();
    $deadline = microtime(true) + 10;
    while (! is_file($releasePath) && microtime(true) < $deadline) {
        usleep(20000);
        clearstatcache(true, $releasePath);
    }
    if (! is_file($releasePath)) {
        throw new RuntimeException('Parent did not release retention worker.');
    }
};
if ($action === 'publish-hold') {
    SubtitleTrack::created($pause);
}
if ($action === 'settings-hold') {
    DB::listen(function ($query) use ($pause): void {
        if (str_contains($query->sql, '"instance_settings"') && str_ends_with($query->sql, 'for update')) {
            $pause();
        }
    });
}
if (str_starts_with($action, 'publish')) {
    $job = SubtitleJob::findOrFail($jobId);
    app(SubtitleGenerationPipeline::class)->prepareCuesAfterCompletedAnalysisBatches($job->id, $job->run_id);
} else {
    app(InstanceSettings::class)->update(['retentionDays' => $retentionDays]);
}
echo "DONE\n";
