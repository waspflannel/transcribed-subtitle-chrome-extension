<?php

namespace App\Http\Controllers;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\UsageLedger;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\SubtitleJobAdmission;
use App\Services\Subtitles\SubtitleJobService;
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
            ->whereIn('processing_version', SubtitleJobService::currentProcessingVersions())
            ->firstOrFail();

        return view('account.job-show', [
            'job' => $job,
            'track' => $job->track,
            'billableMinutes' => $usage->billableMinutes($job->video_duration_seconds),
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

        DB::transaction(function () use ($job, $billing): void {
            $this->releaseReservationSafely($job, $billing);
            $job->delete();
        });

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
                $deleted += DB::transaction(function () use ($jobs, $billing): int {
                    $count = 0;

                    foreach ($jobs as $job) {
                        $this->releaseReservationSafely($job, $billing);
                        $job->delete();
                        $count++;
                    }

                    return $count;
                });
            });

        return redirect()
            ->route('dashboard')
            ->with('jobs_status', $deleted.' subtitle job(s) cleared.');
    }

    private function releaseReservationSafely(SubtitleJob $job, BillingEntitlementService $billing): void
    {
        if (! in_array($job->status, ['running', 'queued'], true)) {
            return;
        }

        $billing->releaseJobReservation($job->loadMissing('user'), 'deleted');
    }

    private function languagePair(SubtitleJob $job): string
    {
        return LanguageCatalog::label($job->effectiveSourceLanguage()).' to '.LanguageCatalog::label((string) $job->target_language);
    }
}
