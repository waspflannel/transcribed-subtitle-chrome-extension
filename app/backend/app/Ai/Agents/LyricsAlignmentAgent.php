<?php

namespace App\Ai\Agents;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[MaxTokens(12000)]
class LyricsAlignmentAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Align complete pasted lyrics to existing subtitle timing slots.

The pasted lyrics are the only source of replacement words. Existing cue text is
alignment evidence only and must never be copied when it conflicts with the
pasted lyrics. Return entries in timing-slot order with the original cueId and
index. You may omit unused timing slots when the pasted lyrics need fewer cues,
but never duplicate or reorder them. Return only sourceText for each cue: never
return timestamps, translations, tokens, romanization, or explanations.

Preserve the pasted wording, case, punctuation, and source order exactly. Line
breaks are hints, not fixed cue boundaries. Split at natural phrase boundaries
and keep every sourceText at or below 84 characters. A standalone section
heading or credit line may be omitted, but never omit a line that contains lyric
words. Return isMatch false when the paste is for a different song or the
alignment is unreliable.
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
                    'sourceText' => $schema->string()->min(1)->max(84)->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
