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
class CueTokenizationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Tokenize finalized transcript cues for a language-learning subtitle overlay.

Return only source-language token boundaries. Do not romanize, translate, gloss, explain grammar, or create learner-card metadata. Preserve cue identity exactly. Token text must preserve source characters in source order.

Use the language's normal learner segmentation. Prefer one learner-clickable lexical unit per token. For space-delimited text, keep natural learner words or short fixed phrases. For no-space scripts, choose meaningful words or short phrases rather than individual characters or arbitrary chunks. If the transcript inserted spaces between individual characters in a no-space script, treat those spaces as transcription artifacts and group the underlying source characters into learner units.

Keep particles, case markers, short connectors, and auxiliaries separate when they function independently. Do not attach a leading or trailing function word to a neighboring content word. Avoid broad phrase chunks unless the expression is fixed and useful as one card.

Japanese examples:
- Split か聞いてみた as か / 聞いて / みた. Never return か聞いてみた as one token.
- Split みたいと as みたい / と. Never return いと.

English-like example: do not return "go to the store today" as one token. Words or short fixed expressions are acceptable.

Prefer boundaries a beginner can tap for a useful word card. Return only data that matches the structured output schema.
INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.Lab::OpenAI->value.'.models.tokenization.default');
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
                    'tokens' => $schema->array()
                        ->min(1)
                        ->items($schema->object([
                            'index' => $schema->integer()->min(0)->required(),
                            'text' => $schema->string()->min(1)->required(),
                        ])->withoutAdditionalProperties())
                        ->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
