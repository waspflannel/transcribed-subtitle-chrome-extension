<?php

namespace App\Services\TranslationAnalysis;

use App\Models\SubtitleTrack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LearningTokenEnrichmentService
{
    public function __construct(
        private readonly TranslationAnalysisProvider $translationAnalysis,
    ) {}

    /**
     * @param  array{trackId: string, cueId: string, tokenIndex: int}  $payload
     * @return array{trackId: string, cueId: string, token: array<string, mixed>}
     */
    public function enrich(array $payload, string $installId): array
    {
        $track = $this->track($payload['trackId'], $installId);
        $cues = $track->cues;
        [$cuePosition, $cue] = $this->cue($cues, $payload['cueId']);
        [$tokenPosition, $token] = $this->token($cue, $payload['tokenIndex']);

        if ($this->hasLearningMetadata($token)) {
            return $this->response($track, $cue, $token);
        }

        Log::info('backend.learning_token_enrichment_started', [
            'track_id' => $track->public_id,
            'job_id' => $track->job->public_id,
            'youtube_video_id' => $track->youtube_video_id,
            'cue_id' => $cue['cueId'],
            'token_index' => $token['index'],
        ]);

        $enrichedToken = Cache::remember(
            $this->cacheKey($track, $cue, $token),
            now()->addDays(30),
            fn (): array => $this->translationAnalysis->enrichToken(
                cue: $cue,
                token: $token,
                sourceLanguage: $track->source_language,
                targetLanguage: $track->target_language,
            ),
        );
        $mergedToken = $this->mergeToken($token, $enrichedToken);

        $cues[$cuePosition]['tokens'][$tokenPosition] = $mergedToken;
        $track->update(['cues' => $cues]);

        Log::info('backend.learning_token_enrichment_completed', [
            'track_id' => $track->public_id,
            'job_id' => $track->job->public_id,
            'youtube_video_id' => $track->youtube_video_id,
            'cue_id' => $cue['cueId'],
            'token_index' => $token['index'],
        ]);

        return $this->response($track->refresh(), $cue, $mergedToken);
    }

    private function track(string $trackId, string $installId): SubtitleTrack
    {
        $track = SubtitleTrack::query()
            ->with('job')
            ->where('public_id', $trackId)
            ->where('expires_at', '>', now())
            ->whereHas('job', fn ($query) => $query->where('install_id', $installId))
            ->first();

        if (! $track instanceof SubtitleTrack) {
            throw new NotFoundHttpException('Generated subtitle track not found.');
        }

        return $track;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cues
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function cue(array $cues, string $cueId): array
    {
        foreach ($cues as $position => $cue) {
            if (is_array($cue) && ($cue['cueId'] ?? null) === $cueId) {
                return [$position, $cue];
            }
        }

        throw new NotFoundHttpException('Subtitle cue not found.');
    }

    /**
     * @param  array<string, mixed>  $cue
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function token(array $cue, int $tokenIndex): array
    {
        $tokens = $cue['tokens'] ?? null;

        if (! is_array($tokens)) {
            throw new NotFoundHttpException('Learning token not found.');
        }

        foreach ($tokens as $position => $token) {
            if (is_array($token) && ($token['index'] ?? null) === $tokenIndex) {
                return [$position, $token];
            }
        }

        throw new NotFoundHttpException('Learning token not found.');
    }

    /**
     * @param  array<string, mixed>  $token
     */
    private function hasLearningMetadata(array $token): bool
    {
        foreach (['lemma', 'root', 'partOfSpeech', 'translation', 'gloss', 'usageNote'] as $field) {
            if (is_string($token[$field] ?? null) && trim($token[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     */
    private function cacheKey(SubtitleTrack $track, array $cue, array $token): string
    {
        return 'learning-token:'.hash('sha256', json_encode([
            'sourceLanguage' => $track->source_language,
            'targetLanguage' => $track->target_language,
            'token' => $token['normalizedText'] ?? $token['text'] ?? '',
            'context' => $cue['sourceText'] ?? '',
            'model' => config('ai.providers.openai.models.enrichment.default', 'gpt-4o-mini'),
            'version' => 'v1',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $existingToken
     * @param  array<string, mixed>  $enrichedToken
     * @return array<string, mixed>
     */
    private function mergeToken(array $existingToken, array $enrichedToken): array
    {
        $token = [
            'index' => (int) $existingToken['index'],
            'text' => (string) $existingToken['text'],
        ];

        if (is_string($existingToken['normalizedText'] ?? null) && trim($existingToken['normalizedText']) !== '') {
            $token['normalizedText'] = $existingToken['normalizedText'];
        } elseif (is_string($enrichedToken['normalizedText'] ?? null) && trim($enrichedToken['normalizedText']) !== '') {
            $token['normalizedText'] = $enrichedToken['normalizedText'];
        }

        foreach (['lemma', 'root', 'partOfSpeech', 'translation', 'gloss', 'romanization', 'usageNote'] as $field) {
            $value = is_string($enrichedToken[$field] ?? null) ? trim($enrichedToken[$field]) : '';

            if ($value === '' && is_string($existingToken[$field] ?? null)) {
                $value = trim($existingToken[$field]);
            }

            if ($value !== '') {
                $token[$field] = $value;
            }
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array{trackId: string, cueId: string, token: array<string, mixed>}
     */
    private function response(SubtitleTrack $track, array $cue, array $token): array
    {
        return [
            'trackId' => $track->public_id,
            'cueId' => (string) $cue['cueId'],
            'token' => $token,
        ];
    }
}
