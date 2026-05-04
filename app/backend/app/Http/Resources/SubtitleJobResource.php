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
        return [
            'jobId' => $this->public_id,
            'youtubeVideoId' => $this->youtube_video_id,
            'sourceLanguage' => $this->source_language,
            'targetLanguage' => $this->target_language,
            'track' => SubtitleTrackResource::make($this->track)->resolve(),
            'createdAt' => $this->created_at->toJSON(),
            'updatedAt' => $this->updated_at->toJSON(),
            'expiresAt' => $this->expires_at->toJSON(),
        ];
    }
}
