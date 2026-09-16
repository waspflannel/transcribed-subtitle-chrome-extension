<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Services\Subtitles\SubtitleQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_all_active_jobs_and_distinct_queue_states(): void
    {
        config(['subtitles.queue.connection' => 'database']);
        SubtitleJob::factory()->count(30)->create(['status' => 'running']);
        $queue = SubtitleQueue::generationName();
        foreach ([[null, time() - 10], [null, time() + 300], [time(), time() - 10]] as [$reserved, $available]) {
            DB::table('jobs')->insert([
                'queue' => $queue, 'payload' => '{}', 'attempts' => 0,
                'reserved_at' => $reserved, 'available_at' => $available, 'created_at' => time(),
            ]);
        }
        $this->assertSame(0, Artisan::call('subtitles:runtime', ['--json' => true]));
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(30, $output['summary']['activeJobCount']);
        $this->assertCount(25, $output['activeJobs']);
        $this->assertSame(['total' => 3, 'ready' => 1, 'delayed' => 1, 'reserved' => 1], $output['summary']['queueDepths'][$queue]);
    }
}
