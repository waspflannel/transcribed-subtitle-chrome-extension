<?php

namespace App\Ai\Agents;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[MaxTokens(12000)]
class LyricsAlignmentAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::OpenAI || $provider === Lab::OpenAI->value
            ? ['reasoning' => ['effort' => 'high'], 'service_tier' => 'fast']
            : [];
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Align complete pasted lyrics to existing subtitle timing slots.

lyricsParts contains the authoritative pasted text in numbered parts. Existing
cue text is timing evidence only. Return entries in timing-slot order with the
original cueId and index, and endPartIndex: the inclusive index of the last
lyric part assigned to that cue. Do not return or rewrite lyric text.

The first cue starts at part 0. Every subsequent cue starts immediately after
the preceding endPartIndex. End indices must strictly increase, and the last
must equal the last supplied part index. Thus every part, including repetitions
and punctuation, is consumed exactly once. The server reconstructs each cue by
joining its parts, collapsing whitespace, and enforcing 84 Unicode code points.
Choose natural phrase boundaries that fit that limit. Parts normally contain
whole words; long unspaced text is supplied as grapheme clusters. Line breaks
are hints, not fixed cue boundaries.

You may omit unused timing slots, but never duplicate or reorder them. Do not
stretch an excerpt across the whole song to fill unused timing slots. Recognized
headings and credits have already been removed. All remaining parts are required.
If validationFeedback is supplied, correct the rejected boundary. Return
isMatch false when the paste is for a different song or alignment is unreliable.
INSTRUCTIONS;
    }

    public function model(): string
    {
        $model = trim((string) config('ai.providers.'.Lab::OpenAI->value.'.models.analysis.default'));

        if ($model === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model_key' => 'analysis.default',
            ]);
        }

        return $model;
    }

    public function timeout(): int
    {
        return (int) config('subtitles.enrichment.timeout_seconds', 120);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'isMatch' => $schema->boolean()->required(),
            'cues' => $schema->array()
                ->items($schema->object([
                    'cueId' => $schema->string()->min(1)->required(),
                    'index' => $schema->integer()->min(0)->required(),
                    'endPartIndex' => $schema->integer()->min(0)->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
