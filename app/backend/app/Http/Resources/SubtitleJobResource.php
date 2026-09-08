<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ValidatesSubtitleResourceFields;
use App\Models\SubtitleJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin SubtitleJob */
class SubtitleJobResource extends JsonResource
{
    use ValidatesSubtitleResourceFields;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $track = $this->track;
        $hasReadyTrack = $this->hasReadyTrack();
        $status = $this->requiredString($this->status, 'status');
        $stage = $this->requiredString($this->stage, 'stage');
        $progressPercent = $this->progress_percent;
        $enrichmentMode = $this->requiredEnrichmentMode($this->enrichment_mode);
        $includeRomanization = $this->requiredBoolean($this->include_romanization, 'include_romanization');
        $includeTranslation = $this->requiredBoolean($this->include_translation, 'include_translation');

        if (! in_array($status, ['queued', 'running', 'completed', 'failed', 'cancelled'], true)) {
            throw new LogicException('Subtitle job has an invalid status.');
        }

        if ($status === 'completed' && ! $hasReadyTrack) {
            throw new LogicException('Completed subtitle job is missing a ready track.');
        }

        if ($hasReadyTrack && $status !== 'completed') {
            throw new LogicException('Subtitle job has a ready track before completion.');
        }

        $progressPercent = $this->requiredProgressPercent($progressPercent);

        $resource = [
            'jobId' => $this->public_id,
            'youtubeVideoId' => $this->youtube_video_id,
            'sourceLanguage' => $this->source_language,
            'targetLanguage' => $this->target_language,
            'enrichmentMode' => $enrichmentMode,
            'includeRomanization' => $includeRomanization,
            'includeTranslation' => $includeTranslation,
            'status' => $status,
            'stage' => $stage,
            'progressPercent' => $progressPercent,
            'createdAt' => $this->created_at->toJSON(),
            'updatedAt' => $this->updated_at->toJSON(),
        ];

        if (is_int($this->video_duration_seconds)) {
            $resource['videoDurationSeconds'] = $this->video_duration_seconds;
        }

        if (is_string($this->detected_source_language) && $this->detected_source_language !== '') {
            $resource['detectedSourceLanguage'] = $this->detected_source_language;
        }

        if ($hasReadyTrack) {
            $resource['track'] = SubtitleTrackResource::make($track)->resolve();
            $resource['expiresAt'] = $track->expires_at->toJSON();
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $resource['errorCode'] = $this->requiredString($this->error_code, 'error_code');
            $resource['message'] = $this->requiredString($this->error_message, 'error_message');
        }

        return $resource;
    }
}
