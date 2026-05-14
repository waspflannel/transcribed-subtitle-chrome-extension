<?php

namespace App\Ai\Agents;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Temperature(0.2)]
#[MaxTokens(6000)]
class CueTranslationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Translate finalized subtitle cues into the requested target language.

Return one translated cue for each input cue in the same order. Preserve cueId, cue index, and sourceText exactly. Do not romanize, retokenize, explain grammar, or create learner-card metadata. Return only a natural non-empty translatedText for each cue in the requested target language.

If sourceLanguage and targetLanguage are the same language, set translatedText exactly equal to sourceText. Return only data that matches the structured output schema.
INSTRUCTIONS;
    }

    public function model(): string
    {
        $model = config('ai.providers.'.Lab::OpenAI->value.'.models.translation.default');

        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.', [
                'provider' => Lab::OpenAI->value,
                'adapter' => 'laravel-ai-sdk',
                'model_key' => 'translation.default',
            ]);
        }

        return trim($model);
    }

    public function timeout(): int
    {
        return (int) config('subtitles.enrichment.timeout_seconds', 120);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cues' => $schema->array()
                ->min(1)
                ->items($schema->object([
                    'cueId' => $schema->string()->min(1)->required(),
                    'index' => $schema->integer()->min(0)->required(),
                    'sourceText' => $schema->string()->min(1)->required(),
                    'translatedText' => $schema->string()->min(1)->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
