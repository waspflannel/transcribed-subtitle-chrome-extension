<?php

namespace Tests\Integration;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Services\InstanceSettings;
use App\Services\Subtitles\SubtitleJobArtifactStore;
use App\Services\Transcription\TimestampedTranscript;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RetentionPublicationConcurrencyTest extends TestCase
{
    private ?string $schema = null;

    private array $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $port = (int) getenv('SUBTITLE_DISPOSABLE_PG_PORT');
        $database = getenv('SUBTITLE_DISPOSABLE_PG_DATABASE');
        if ($port === 0 || ! $database) {
            $this->markTestSkipped('Set SUBTITLE_DISPOSABLE_PG_PORT and SUBTITLE_DISPOSABLE_PG_DATABASE for disposable PostgreSQL.');
        }
        $this->assertGreaterThan(1024, $port, 'Use a dedicated disposable PostgreSQL port.');
        $this->assertMatchesRegularExpression('/^subtitle_review_test_[a-z0-9]+$/', $database);
        $this->schema = 'byok_retention_'.bin2hex(random_bytes(8));
        $this->connection = [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => $port, 'database' => $database,
            'username' => getenv('SUBTITLE_DISPOSABLE_PG_USERNAME') ?: null,
            'password' => getenv('SUBTITLE_DISPOSABLE_PG_PASSWORD') ?: null,
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $this->schema, 'sslmode' => 'prefer',
        ];
        config(['database.connections.retention_test' => $this->connection]);
        DB::setDefaultConnection('retention_test');
        DB::statement('CREATE SCHEMA "'.$this->schema.'"');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schema !== null) {
                DB::connection('retention_test')->statement('DROP SCHEMA "'.$this->schema.'" CASCADE');
                DB::purge('retention_test');
                DB::setDefaultConnection('sqlite');
            }
        } finally {
            parent::tearDown();
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_retention_changes_serialize_with_track_publication_in_both_orders(bool $publicationFirst): void
    {
        $settings = app(InstanceSettings::class);
        $settings->update(['retentionDays' => $publicationFirst ? 7 : null]);
        $nextRetention = $publicationFirst ? null : 7;
        $job = SubtitleJob::factory()->create(['stage' => 'tokenizing']);
        $artifacts = app(SubtitleJobArtifactStore::class);
        $artifacts->putTranscript($job, new TimestampedTranscript('eng', 2, [], "WEBVTT\n\n"));
        $cues = [[
            'cueId' => 'cue-0001', 'index' => 0, 'startMs' => 0, 'endMs' => 2000,
            'sourceText' => 'Hello', 'translatedText' => '',
            'tokens' => [['index' => 0, 'text' => 'Hello', 'normalizedText' => 'hello']],
        ]];
        $artifacts->putCueCollection($job, SubtitleJobArtifactStore::DRAFT_CUES, $cues);
        $artifacts->putCueBatchResult($job, SubtitleJobArtifactStore::ANALYZED_CUES, 0, new CueEnrichmentResult($cues));
        $releasePath = storage_path('retention-release');
        $first = $this->worker($publicationFirst ? 'publish-hold' : 'settings-hold', $job, $releasePath, $nextRetention);
        $secondAction = $publicationFirst ? 'settings' : 'publish';
        $second = $this->worker($secondAction, $job, $releasePath, $nextRetention);
        try {
            $first->start();
            $this->waitUntil(fn (): bool => str_contains($first->getOutput(), 'HELD'), $first);
            $second->start();
            $this->waitUntil(fn (): bool => (bool) DB::selectOne(
                'select exists(select 1 from pg_stat_activity where application_name = ? and wait_event_type = ?) as waiting',
                [$this->schema.'_'.$secondAction, 'Lock'],
            )->waiting, $second);
            $this->assertStringNotContainsString('DONE', $second->getOutput());

            touch($releasePath);
            foreach ([$first, $second] as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }

            $track = SubtitleTrack::where('subtitle_job_id', $job->id)->firstOrFail();
            $this->assertSame('completed', $job->refresh()->status);
            $this->assertSame($nextRetention, $settings->retentionDays());
            $expected = $nextRetention === null ? null : $track->generated_at->addDays($nextRetention)->toDateTimeString();
            $this->assertSame($expected, $track->expires_at?->toDateTimeString());
            $this->assertSame($expected, $job->expires_at?->toDateTimeString());
        } finally {
            touch($releasePath);
            foreach ([$first, $second] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            unlink($releasePath);
        }
    }

    private function worker(string $action, SubtitleJob $job, string $releasePath, ?int $retentionDays): Process
    {
        return new Process([
            PHP_BINARY, 'tests/Fixtures/retention-worker.php', json_encode($this->connection, JSON_THROW_ON_ERROR),
            $action, (string) $job->id, $releasePath, json_encode($retentionDays, JSON_THROW_ON_ERROR),
        ], base_path(), timeout: 15);
    }

    private function waitUntil(callable $condition, Process $worker): void
    {
        $deadline = microtime(true) + 5;
        while (! $condition() && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertTrue($condition(), $worker->getErrorOutput().$worker->getOutput());
    }
}
