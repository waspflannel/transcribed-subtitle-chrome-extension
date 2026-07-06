<?php

namespace App\Services\Subtitles;

use App\Exceptions\SubtitleProcessingException;
use App\Models\SubtitleJob;
use App\Models\SubtitleJobArtifact;
use Illuminate\Database\Eloquent\Collection;

/**
 * Assembles the cues already available for a still-running job from its
 * batch artifacts: source text from the draft cues, translations and
 * romanization overlaid per batch as they land. Tokens are never included --
 * word cards need the finalized track.
 */
class SubtitlePartialTrackAssembler
{
    /**
     * Contract-shaped PartialTrackResponse payload, or null when the draft
     * cues have not been written yet (pre-transcription).
     *
     * @return array<string, mixed>|null
     */
    public function assemble(SubtitleJob $job): ?array
    {
        $artifacts = SubtitleJobArtifact::query()
            ->where('subtitle_job_id', $job->id)
            ->where('run_id', $job->run_id)
            ->whereIn('artifact_type', [
                SubtitleJobArtifactStore::DRAFT_CUES,
                SubtitleJobArtifactStore::TRANSLATED_CUES,
                SubtitleJobArtifactStore::ROMANIZED_CUES,
                SubtitleJobArtifactStore::MERGED_CUES,
            ])
            ->orderBy('batch_index')
            ->get();

        $draft = $artifacts->firstWhere('artifact_type', SubtitleJobArtifactStore::DRAFT_CUES);

        if ($draft === null) {
            return null;
        }

        $cues = [];

        foreach ($this->artifactCues($draft) as $cue) {
            if (! is_string($cue['cueId'] ?? null)) {
                $this->failInvalidArtifact(SubtitleJobArtifactStore::DRAFT_CUES);
            }

            $cues[$cue['cueId']] = [
                'cueId' => $cue['cueId'],
                'index' => (int) $cue['index'],
                'startMs' => (int) $cue['startMs'],
                'endMs' => (int) $cue['endMs'],
                'sourceText' => (string) $cue['sourceText'],
            ];
        }

        if ($cues === []) {
            return null;
        }

        $revision = 1;

        // Later pipeline output wins: translations and romanization land per
        // batch, and the merged artifact (written just before enrichment)
        // supersedes both.
        foreach ($this->artifactsOfType($artifacts, SubtitleJobArtifactStore::TRANSLATED_CUES) as $artifact) {
            $revision++;
            $this->overlay($cues, $this->artifactCues($artifact), ['translatedText']);
        }

        foreach ($this->artifactsOfType($artifacts, SubtitleJobArtifactStore::ROMANIZED_CUES) as $artifact) {
            $revision++;
            $this->overlay($cues, $this->artifactCues($artifact), ['romanization']);
        }

        foreach ($this->artifactsOfType($artifacts, SubtitleJobArtifactStore::MERGED_CUES) as $artifact) {
            $revision++;
            $this->overlay($cues, $this->artifactCues($artifact), ['translatedText', 'romanization']);
        }

        return [
            'jobId' => $job->public_id,
            'youtubeVideoId' => $job->youtube_video_id,
            'revision' => $revision,
            'cues' => array_values($cues),
        ];
    }

    /**
     * @param  Collection<int, SubtitleJobArtifact>  $artifacts
     * @return Collection<int, SubtitleJobArtifact>
     */
    private function artifactsOfType(Collection $artifacts, string $artifactType): Collection
    {
        return $artifacts->where('artifact_type', $artifactType)->values();
    }

    /**
     * @param  array<string, array<string, mixed>>  $cues
     * @param  array<int, array<string, mixed>>  $overlayCues
     * @param  array<int, string>  $fields
     */
    private function overlay(array &$cues, array $overlayCues, array $fields): void
    {
        foreach ($overlayCues as $overlayCue) {
            $cueId = $overlayCue['cueId'] ?? null;

            if (! is_string($cueId) || ! array_key_exists($cueId, $cues)) {
                continue;
            }

            foreach ($fields as $field) {
                $value = $overlayCue[$field] ?? null;

                if (is_string($value) && $value !== '') {
                    $cues[$cueId][$field] = $value;
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function artifactCues(SubtitleJobArtifact $artifact): array
    {
        $payload = $artifact->payload;
        $cues = is_array($payload) ? ($payload['cues'] ?? null) : null;

        if (! is_array($cues)) {
            $this->failInvalidArtifact($artifact->artifact_type);
        }

        return array_values($cues);
    }

    private function failInvalidArtifact(string $artifactType): never
    {
        throw SubtitleProcessingException::enrichmentFailed(
            'Subtitle processing state is incomplete.',
            ['artifact_type' => $artifactType],
        );
    }
}
