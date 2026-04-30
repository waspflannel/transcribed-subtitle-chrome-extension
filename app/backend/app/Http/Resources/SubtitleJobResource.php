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

        if ($this->progress_stage !== null && $this->progress_percent !== null) {
            $response['progress'] = [
                'stage' => $this->progress_stage,
                'percent' => $this->progress_percent,
            ];

            if ($this->progress_message !== null) {
                $response['progress']['message'] = $this->progress_message;
            }
        }

        if ($this->track !== null) {
            $response['trackId'] = $this->track->public_id;
        }

        if ($this->error_code !== null && $this->error_message !== null) {
            $response['error'] = [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ];

            if ($this->error_details !== null) {
                $response['error']['details'] = $this->error_details;
            }
        }

        if ($this->expires_at !== null) {
            $response['expiresAt'] = $this->expires_at->toJSON();
        }

        return $response;
    }
}
