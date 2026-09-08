<?php

namespace App\Http\Controllers;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Audio\SubtitleAudioWorkspace;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\UsageLedger;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\SubtitleJobAdmission;
use App\Services\Subtitles\SubtitleJobLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            'pageTitle' => 'Subtitle job '.$job->public_id.' | '.config('marketing.product_name'),
            'metaDescription' => 'Public-safe subtitle job status for support.',
            'canonicalUrl' => route('dashboard.jobs.show', ['jobId' => $job->public_id]),
            'robots' => 'noindex,nofollow',
            'bodyClass' => 'app-body',
        ]);
    }

    public function destroy(
        Request $request,
        string $jobId,
        BillingEntitlementService $billing,
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

        $this->deleteCurrentJob($job, $billing);

        // Deleting a running job frees a processing slot for a queued one.
        $admission->promoteQueuedJobs($user->id);

        return redirect()
            ->route('dashboard')
            ->with('jobs_status', 'Subtitle job '.$job->public_id.' was deleted.');
    }

    public function clearAll(Request $request, BillingEntitlementService $billing): RedirectResponse
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
            ->chunkById(200, function (Collection $jobs) use ($billing, &$deleted): void {
                foreach ($jobs as $job) {
                    $deleted += (int) $this->deleteCurrentJob($job, $billing);
                }
            });

        return redirect()
            ->route('dashboard')
            ->with('jobs_status', $deleted.' subtitle job(s) cleared.');
    }

    private function deleteCurrentJob(SubtitleJob $job, BillingEntitlementService $billing): bool
    {
        return DB::transaction(function () use ($job, $billing): bool {
            $current = SubtitleJobLock::current($job->id, userId: $job->user_id);

            if ($current === null) {
                return false;
            }

            if ($current->status !== 'completed') {
                $billing->releaseJobReservation($current, 'deleted');
            }

            $runId = $current->run_id;
            $current->delete();
            DB::afterCommit(fn () => SubtitleAudioWorkspace::delete($runId));

            return true;
        }, attempts: 5);
    }

    private function languagePair(SubtitleJob $job): string
    {
        return LanguageCatalog::label($job->effectiveSourceLanguage()).' to '.LanguageCatalog::label((string) $job->target_language);
    }
}
