<?php

namespace App\Http\Controllers;

use App\Models\SubtitleJob;
use App\Models\User;
use App\Services\Billing\UsageLedger;
use App\Services\Languages\LanguageCatalog;
use App\Services\Subtitles\SubtitleJobService;
use Illuminate\Http\Request;
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
            ->whereIn('processing_version', SubtitleJobService::CURRENT_PROCESSING_VERSIONS)
            ->firstOrFail();

        return view('account.job-show', [
            'job' => $job,
            'track' => $job->track,
            'billableMinutes' => $usage->billableMinutes($job->video_duration_seconds),
            'languagePair' => $this->languagePair($job),
            'pageTitle' => 'Subtitle job '.$job->public_id.' | AI Language Subtitles',
            'metaDescription' => 'Public-safe subtitle job status for support.',
            'canonicalUrl' => route('dashboard.jobs.show', ['jobId' => $job->public_id]),
            'robots' => 'noindex,nofollow',
            'bodyClass' => 'app-body',
        ]);
    }

    private function languagePair(SubtitleJob $job): string
    {
        $source = $job->detected_source_language ?: $job->source_language;

        return LanguageCatalog::label((string) $source).' to '.LanguageCatalog::label((string) $job->target_language);
    }
}
