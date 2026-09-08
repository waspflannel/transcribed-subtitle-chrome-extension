<?php

namespace App\Ai\Agents;

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
#[MaxTokens(1200)]
class LearningTokenCardAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::OpenAI || $provider === Lab::OpenAI->value
            ? config('ai.providers.openai.provider_options', [])
            : [];
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Create one concise learner card for one clicked subtitle token.

Return exactly one token object for requestedToken. The returned token text must match requestedToken.text exactly. Include short gloss or translation metadata for the target language. Add lemma, root, partOfSpeech, romanization, or usageNote only when useful.

For non-Latin source text, include romanization when helpful. For Latin-script languages, omit romanization unless it helps pronunciation. Use learner-standard romanization when applicable, such as Hepburn for Japanese and pinyin for Mandarin. Include only metadata that helps a learner understand the token in context. Return only data that matches the structured output schema.
INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.Lab::OpenAI->value.'.models.enrichment.default');
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
