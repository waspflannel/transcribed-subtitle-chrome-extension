<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ValidatesSubtitleResourceFields;
use App\Models\SubtitleJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @mixin SubtitleJob */
class SubtitleJobHistoryResource extends JsonResource
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
        $enrichmentMode = $this->requiredEnrichmentMode($this->enrichment_mode);
        $includeRomanization = $this->requiredBoolean($this->include_romanization, 'include_romanization');
        $includeTranslation = $this->requiredBoolean($this->include_translation, 'include_translation');

        if (! in_array($status, ['running', 'completed', 'failed'], true)) {
            throw new LogicException('Subtitle job has an invalid status.');
        }

        if ($status === 'completed' && ! $hasReadyTrack) {
            throw new LogicException('Completed subtitle job is missing a ready track.');
        }

        if ($hasReadyTrack && $status !== 'completed') {
            throw new LogicException('Subtitle job has a ready track before completion.');
        }

        $item = [
            'youtubeVideoId' => $this->youtube_video_id,
            'youtubeUrl' => $this->youtube_url,
            'status' => $status,
            'startedAt' => $this->created_at->toJSON(),
            'lastUpdatedAt' => $this->updated_at->toJSON(),
            'sourceLanguage' => $this->source_language,
            'targetLanguage' => $this->target_language,
            'enrichmentMode' => $enrichmentMode,
            'includeRomanization' => $includeRomanization,
            'includeTranslation' => $includeTranslation,
            'jobId' => $this->public_id,
            'stage' => $this->requiredString($this->stage, 'stage'),
            'progressPercent' => $this->requiredProgressPercent($this->progress_percent),
        ];

        if (is_int($this->video_duration_seconds)) {
            $item['videoDurationSeconds'] = $this->video_duration_seconds;
        }

        if (is_string($this->detected_source_language) && $this->detected_source_language !== '') {
            $item['detectedSourceLanguage'] = $this->detected_source_language;
        }

        if ($track !== null) {
            $item['completedAt'] = $track->generated_at->toJSON();
            $item['trackId'] = $track->public_id;
            $item['expiresAt'] = $track->expires_at->toJSON();
        }

        if ($status === 'failed') {
            $item['completedAt'] = $this->updated_at->toJSON();
            $item['errorCode'] = $this->requiredString($this->error_code, 'error_code');
            $item['message'] = $this->requiredString($this->error_message, 'error_message');
        }

        return $item;
    }
}
