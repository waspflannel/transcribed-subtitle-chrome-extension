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
#[Temperature(0.2)]
#[MaxTokens(1200)]
class LearningTokenCardAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Create one concise learner card for one clicked subtitle token.

Preserve the requested token text exactly. Include only metadata that helps a learner understand the token in context. Return only data that matches the structured output schema.
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
            'token' => $schema->object([
                'index' => $schema->integer()->min(0)->required(),
                'text' => $schema->string()->min(1)->required(),
                'normalizedText' => $schema->string()->min(1)->nullable()->required(),
                'lemma' => $schema->string()->min(1)->nullable()->required(),
                'root' => $schema->string()->min(1)->nullable()->required(),
                'partOfSpeech' => $schema->string()->min(1)->nullable()->required(),
                'translation' => $schema->string()->min(1)->nullable()->required(),
                'gloss' => $schema->string()->min(1)->nullable()->required(),
                'romanization' => $schema->string()->min(1)->nullable()->required(),
                'usageNote' => $schema->string()->min(1)->nullable()->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }
}
