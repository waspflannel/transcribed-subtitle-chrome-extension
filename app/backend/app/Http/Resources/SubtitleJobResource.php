<?php

namespace App\Http\Resources;

use App\Models\SubtitleJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubtitleJob */
class SubtitleJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $track = $this->track;
        $hasReadyTrack = $track !== null && ! $track->isExpired();
        $status = $hasReadyTrack ? 'completed' : ($this->status === 'failed' ? 'failed' : 'running');

        $resource = [
            'jobId' => $this->public_id,
            'youtubeVideoId' => $this->youtube_video_id,
            'sourceLanguage' => $this->source_language,
            'targetLanguage' => $this->target_language,
            'status' => $status,
            'stage' => is_string($this->stage) && $this->stage !== '' ? $this->stage : 'preparing',
            'progressPercent' => $hasReadyTrack ? 100 : (int) $this->progress_percent,
            'createdAt' => $this->created_at->toJSON(),
            'updatedAt' => $this->updated_at->toJSON(),
        ];

        if (is_string($this->detected_source_language) && $this->detected_source_language !== '') {
            $resource['detectedSourceLanguage'] = $this->detected_source_language;
        }

        if ($hasReadyTrack) {
            $resource['track'] = SubtitleTrackResource::make($track)->resolve();
            $resource['expiresAt'] = $track->expires_at->toJSON();
        }

        if ($status === 'failed') {
            $resource['message'] = $this->error_message ?: 'Generation did not complete.';
        }

        return $resource;
    }
}
