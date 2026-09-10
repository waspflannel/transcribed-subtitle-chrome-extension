<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxTokens(1200)]
class LearningTokenCardAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(public readonly ?string $sourceLanguage = null) {}

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::OpenAI || $provider === Lab::OpenAI->value
            ? config('ai.providers.openai.provider_options', [])
            : [];
    }

    public function instructions(): Stringable|string
    {
        return implode("\n\n", [
            'Create one concise learner card for one clicked subtitle token. Return exactly one token object for requestedToken. Copy requestedToken.index exactly; do not renumber it to zero. requestedToken.text is authoritative: explain that exact word or phrase without splitting or rewriting it. The server restores token text; do not echo it.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::WORD_CARD,
            'Preserve an existing non-empty requestedToken.romanization. Otherwise generate romanization for a token containing non-Latin letters, and return null for a Latin-only token.',
            SubtitlePromptRules::romanization($this->sourceLanguage),
        ]);
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.config('ai.default').'.models.enrichment.default');
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
