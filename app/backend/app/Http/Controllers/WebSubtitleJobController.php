<?php

namespace App\Http\Controllers;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Billing\UsageLedger;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\SubtitleJobAdmission;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class WebSubtitleJobController extends Controller
{
    public function show(Request $request, string $jobId, UsageLedger $usage): View
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $job = SubtitleJob::query()
            ->with('track')
            ->whereBelongsTo($user)
            ->where('public_id', $jobId)
            ->firstOrFail();

        return view('account.job-show', [
            'job' => $job,
            'track' => $job->track,
            'usage' => $usage->usageForJob($job),
            'languagePair' => $this->languagePair($job),
            'pageTitle' => __('Subtitle job :job', ['job' => $job->public_id]).' | '.config('marketing.product_name'),
            'metaDescription' => __('Public-safe subtitle job status for support.'),
            'canonicalUrl' => route('dashboard.jobs.show', ['jobId' => $job->public_id]),
            'robots' => 'noindex,nofollow',
            'bodyClass' => 'app-body',
        ]);
    }

    public function destroy(
        Request $request,
        string $jobId,
        SubtitleJobService $subtitleJobs,
        SubtitleJobAdmission $admission,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $job = SubtitleJob::query()
            ->whereBelongsTo($user)
            ->where('public_id', $jobId)
            ->firstOrFail();

        $subtitleJobs->delete($job);

        // Deleting a running job frees a processing slot for a queued one.
        $admission->promoteQueuedJobs($user->id);

        return redirect()
            ->route('dashboard')
            ->with('jobs_status', __('Subtitle job :job was deleted.', ['job' => $job->public_id]));
    }

    public function clearAll(Request $request, SubtitleJobService $subtitleJobs): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $total = SubtitleJob::query()->whereBelongsTo($user)->count();

        if ($total === 0) {
            return redirect()
                ->route('dashboard')
                ->with('jobs_status', 'No subtitle jobs to clear.');
        }

        $deleted = 0;

        SubtitleJob::query()
            ->whereBelongsTo($user)
            ->chunkById(200, function (Collection $jobs) use ($subtitleJobs, &$deleted): void {
                foreach ($jobs as $job) {
                    $deleted += (int) $subtitleJobs->delete($job);
                }
            });

        return redirect()
            ->route('dashboard')
            ->with('jobs_status', __(':count subtitle job(s) cleared.', ['count' => $deleted]));
    }

    private function languagePair(SubtitleJob $job): string
    {
        return __(':source to :target', ['source' => __(LanguageCatalog::label($job->effectiveSourceLanguage())), 'target' => __(LanguageCatalog::label((string) $job->target_language))]);
    }
}
