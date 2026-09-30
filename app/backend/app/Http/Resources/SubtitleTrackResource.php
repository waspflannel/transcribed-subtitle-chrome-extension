<?php

namespace App\Http\Resources;

use App\Models\SubtitleTrack;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubtitleTrack */
class SubtitleTrackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = [
            'trackId' => $this->public_id,
            'jobId' => $this->job->public_id,
            'youtubeVideoId' => $this->youtube_video_id,
            'sourceLanguage' => $this->source_language,
            'targetLanguage' => $this->target_language,
            'generatedAt' => $this->generated_at->toJSON(),
            'expiresAt' => $this->expires_at?->toJSON(),
            'webVtt' => $this->web_vtt,
            'cues' => $this->cues,
        ];

        if (is_string($this->detected_source_language) && $this->detected_source_language !== '') {
            $resource['detectedSourceLanguage'] = $this->detected_source_language;
        }

        return $resource;
    }
}
