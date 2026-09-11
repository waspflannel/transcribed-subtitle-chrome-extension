<?php

namespace App\Services\TranslationAnalysis;

use App\Ai\SubtitleModel;
use App\Exceptions\BillingEntitlementException;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Services\Billing\BillingEntitlementService;
use App\Support\SubtitleProcessingVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LearningTokenEnrichmentService
{
    public function __construct(
        private readonly LaravelAiTranslationAnalysisProvider $translationAnalysis,
        private readonly BillingEntitlementService $billing,
    ) {}

    /**
     * @param  array{trackId: string, cueId: string, tokenIndex: int}  $payload
     * @return array{trackId: string, cueId: string, token: array<string, mixed>}
     */
    public function enrich(array $payload, User $user): array
    {
        $track = $this->track($payload['trackId'], $user);
        $cues = $track->cues;
        [, $cue] = $this->cue($cues, $payload['cueId']);
        [, $token] = $this->token($cue, $payload['tokenIndex']);

        if ($this->hasLearningMetadata($token)) {
            return $this->response($track, $cue, $token);
        }

        if ($this->billing->activePlan($user) === null) {
            throw BillingEntitlementException::paymentRequired();
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
                selection: SubtitleModel::forJob($track->job),
            ),
        );

        $response = DB::transaction(function () use ($track, $user, $payload, $enrichedToken): array {
            $lockedTrack = SubtitleTrack::query()
                ->with('job')
                ->whereKey($track->getKey())
                ->where('expires_at', '>', now())
                ->whereHas('job', fn ($query) => $query->whereBelongsTo($user))
                ->lockForUpdate()
                ->first();

            if (! $lockedTrack instanceof SubtitleTrack) {
                throw new NotFoundHttpException('Generated subtitle track not found.');
            }

            $freshCues = $lockedTrack->cues;
            [$freshCuePosition, $freshCue] = $this->cue($freshCues, $payload['cueId']);
            [$freshTokenPosition, $freshToken] = $this->token($freshCue, $payload['tokenIndex']);

            if ($this->hasLearningMetadata($freshToken)) {
                return $this->response($lockedTrack, $freshCue, $freshToken);
            }

            $freshMergedToken = $this->mergeToken($freshToken, $enrichedToken);
            $freshCues[$freshCuePosition]['tokens'][$freshTokenPosition] = $freshMergedToken;
            $freshCue['tokens'][$freshTokenPosition] = $freshMergedToken;
            $lockedTrack->update(['cues' => $freshCues]);

            return $this->response($lockedTrack, $freshCue, $freshMergedToken);
        });

        Log::info('backend.learning_token_enrichment_completed', [
            'track_id' => $track->public_id,
            'job_id' => $track->job->public_id,
            'youtube_video_id' => $track->youtube_video_id,
            'cue_id' => $cue['cueId'],
            'token_index' => $token['index'],
        ]);

        return $response;
    }

    private function track(string $trackId, User $user): SubtitleTrack
    {
        $track = SubtitleTrack::query()
            ->with('job')
            ->where('public_id', $trackId)
            ->where('expires_at', '>', now())
            ->whereHas('job', fn ($query) => $query->whereBelongsTo($user))
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
            if ($cue['cueId'] === $cueId) {
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
        foreach ($cue['tokens'] as $position => $token) {
            if ($token['index'] === $tokenIndex) {
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
        foreach (['translation', 'gloss'] as $field) {
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
            'detectedSourceLanguage' => $track->detected_source_language,
            'targetLanguage' => $track->target_language,
            'token' => $token['normalizedText'],
            'tokenIndex' => $token['index'],
            'context' => $cue['sourceText'],
            'translation' => $cue['translatedText'] ?? null,
            'provider' => $track->job->ai_provider,
            'model' => $track->job->ai_model,
            'version' => SubtitleProcessingVersion::LEARNING_TOKEN_CACHE,
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
            'normalizedText' => $existingToken['normalizedText'],
        ];

        foreach (['lemma', 'root', 'partOfSpeech', 'translation', 'gloss', 'romanization', 'usageNote'] as $field) {
            $value = is_string($enrichedToken[$field] ?? null) ? trim($enrichedToken[$field]) : '';

            if ($field === 'romanization' && is_string($existingToken[$field] ?? null) && trim($existingToken[$field]) !== '') {
                $value = trim($existingToken[$field]);
            }

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
