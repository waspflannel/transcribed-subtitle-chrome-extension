<?php

namespace Tests\Feature;

use App\Models\SubtitleJob;
use App\Models\SubtitleTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebSubtitleJobSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_support_id_opens_private_job_details_and_links_to_support(): void
    {
        $owner = User::factory()->create();
        $job = SubtitleJob::factory()->for($owner)->create([
            'status' => 'completed',
            'progress_percent' => 100,
        ]);
        $track = SubtitleTrack::factory()->for($job, 'job')->create([
            'web_vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nprivate subtitle text\n",
            'cues' => [['sourceText' => 'private subtitle text']],
        ]);

        $dashboard = $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk();
        $supportLink = $dashboard->viewData('recentJobs')->firstWhere('jobId', $job->public_id)['href'];
        $dashboard->assertSee('href="'.$supportLink.'">'.$job->public_id.'</a>', false);

        $this->get($supportLink)
            ->assertOk()
            ->assertSeeText($job->public_id)
            ->assertSeeText($track->public_id)
            ->assertSeeText('100%')
            ->assertSee('href="'.route('marketing.support').'">Contact support</a>', false)
            ->assertSee('name="robots" content="noindex,nofollow"', false)
            ->assertDontSeeText('private subtitle text');

        $this->flushSession();
        $this->actingAs(User::factory()->create())->get($supportLink)
            ->assertNotFound();
    }

    public function test_support_job_details_require_sign_in(): void
    {
        $job = SubtitleJob::factory()->for(User::factory())->create();

        $this->get(route('dashboard.jobs.show', ['jobId' => $job->public_id]))
            ->assertRedirect(route('login'));
    }
}
