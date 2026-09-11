<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use Illuminate\Support\Arr;

class SubtitlePartialTrackAssembler
{
    /** Preview fixed source cues immediately, then add validated annotations as batches arrive. */
    public function assemble(SubtitleJob $job): ?array
    {
        $artifacts = SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('run_id', $job->run_id)
            ->whereIn('artifact_type', [SubtitleJobArtifactStore::DRAFT_CUES, SubtitleJobArtifactStore::ANALYZED_CUES])
            ->orderBy('batch_index')->get();
        $draft = $artifacts->firstWhere('artifact_type', SubtitleJobArtifactStore::DRAFT_CUES);
        if ($draft === null) {
            return null;
        }
        $cues = [];
        foreach ($draft->payload['cues'] as $cue) {
            $cues[$cue['cueId']] = Arr::only($cue, ['cueId', 'index', 'startMs', 'endMs', 'sourceText']);
        }
        $revision = $draft->payload['revision'] ?? 1;
        $analyzed = [];
        foreach ($artifacts->where('artifact_type', SubtitleJobArtifactStore::ANALYZED_CUES) as $artifact) {
            foreach ($artifact->payload['cues'] as $cue) {
                $cues[$cue['cueId']] = [...$cues[$cue['cueId']], ...Arr::only($cue, ['translatedText', 'romanization'])];
                $analyzed[$cue['cueId']] = true;
            }
            $revision++;
        }

        $readyThroughMs = 0;
        foreach ($cues as $cue) {
            if (! isset($analyzed[$cue['cueId']])) {
                break;
            }
            $readyThroughMs = $cue['endMs'];
        }

        return $cues === [] ? null : [
            'jobId' => $job->public_id,
            'youtubeVideoId' => $job->youtube_video_id,
            'revision' => $revision,
            'readyThroughMs' => $readyThroughMs,
            'cues' => array_values($cues),
        ];
    }
}
