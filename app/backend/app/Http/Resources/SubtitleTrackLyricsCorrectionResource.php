<?php

namespace App\Http\Resources;

use App\Models\SubtitleTrackLyricsCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubtitleTrackLyricsCorrection */
class SubtitleTrackLyricsCorrectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resource = [
            'attemptId' => $this->attempt_id,
            'status' => $this->status,
            'updatedAt' => $this->updated_at->toJSON(),
        ];

        if ($this->status === 'completed') {
            $resource['track'] = SubtitleTrackResource::make($this->resource->track)->resolve();
        }

        if ($this->status === 'failed') {
            $resource['errorCode'] = $this->error_code;
            $resource['message'] = $this->error_message;
        }

        return $resource;
    }
}
