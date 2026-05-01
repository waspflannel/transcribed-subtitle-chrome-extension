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
        $response = [
            'jobId' => $this->public_id,
            'status' => $this->status->value,
            'youtubeVideoId' => $this->youtube_video_id,
            'sourceLanguage' => $this->source_language,
            'targetLanguage' => $this->target_language,
            'createdAt' => $this->created_at->toJSON(),
            'updatedAt' => $this->updated_at->toJSON(),
        ];

        if ($this->track !== null && ! $this->track->isExpired()) {
            $response['track'] = SubtitleTrackResource::make($this->track)->resolve();
        }

        if ($this->expires_at !== null) {
            $response['expiresAt'] = $this->expires_at->toJSON();
        }

        return $response;
    }
}
