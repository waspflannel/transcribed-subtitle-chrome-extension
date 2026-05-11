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
Romanize subtitle cues written in Arabic script for display in transcript-first mode.

Do not translate, explain grammar, or create word-card metadata. Preserve cue and token identity exactly. Return readable Latin-script pronunciation only.
INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config(
            'ai.providers.'.Lab::OpenAI->value.'.models.enrichment.default',
            config('ai.providers.'.Lab::OpenAI->value.'.models.text.default', 'gpt-4o-mini'),
        );
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
