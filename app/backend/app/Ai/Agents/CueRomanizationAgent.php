<?php

namespace App\Ai\Agents;

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
#[Temperature(0.1)]
#[MaxTokens(5000)]
class CueRomanizationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Romanize finalized, pre-tokenized subtitle cues written in non-Latin scripts.

Do not translate, explain grammar, create word-card metadata, or change token boundaries. Preserve cue identity, source text, token count, token indexes, and token text exactly. Add only readable learner-standard Latin-script pronunciation, such as Hepburn for Japanese and pinyin for Mandarin. Return only data that matches the structured output schema.
INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.Lab::OpenAI->value.'.models.romanization.default');
    }

    public function timeout(): int
    {
        return (int) config('subtitles.enrichment.timeout_seconds', 120);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dialect' => $schema->string()->min(1)->required(),
            'cues' => $schema->array()
                ->min(1)
                ->items($schema->object([
                    'cueId' => $schema->string()->min(1)->required(),
                    'index' => $schema->integer()->min(0)->required(),
                    'sourceText' => $schema->string()->min(1)->required(),
                    'translatedText' => $schema->string()->min(1)->required(),
                    'romanization' => $schema->string()->min(1)->required(),
                    'tokens' => $schema->array()
                        ->min(1)
                        ->items($schema->object([
                            'index' => $schema->integer()->min(0)->required(),
                            'text' => $schema->string()->min(1)->required(),
                            'romanization' => $schema->string()->min(1)->required(),
                        ])->withoutAdditionalProperties())
                        ->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
