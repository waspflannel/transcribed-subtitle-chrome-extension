<?php

namespace App\Http\Resources;

use App\Models\SubtitleJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

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
        $status = $this->requiredString($this->status, 'status');
        $stage = $this->requiredString($this->stage, 'stage');
        $progressPercent = $this->progress_percent;
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

        if (! is_int($progressPercent)) {
            throw new LogicException('Subtitle job is missing progress percent.');
        }

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

        if ($status === 'failed') {
            $resource['errorCode'] = $this->requiredString($this->error_code, 'error_code');
            $resource['message'] = $this->requiredString($this->error_message, 'error_message');
        }

        return $resource;
    }

    private function requiredEnrichmentMode(mixed $value): string
    {
        if ($value !== 'on_demand' && $value !== 'full') {
            throw new LogicException('Subtitle job has an invalid enrichment_mode.');
        }

        return $value;
    }

    private function requiredBoolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new LogicException("Subtitle job is missing {$field}.");
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new LogicException("Subtitle job is missing {$field}.");
        }

        return $value;
    }
}
