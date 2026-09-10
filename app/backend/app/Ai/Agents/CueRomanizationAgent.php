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

#[MaxTokens(5000)]
class CueRomanizationAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
            'Romanize finalized, pre-tokenized subtitle cues. Return one cue for each input cue in the same order. Do not translate, retokenize, explain grammar, or create learner-card metadata. Preserve cueId and cue index exactly. For every supplied token, return the same index and a non-empty romanization. Do not echo the token text. Fill whole-cue romanization too. For tokens containing only Latin letters, digits, or symbols, copy their text into the required romanization field. Use "unknown" for dialect when uncertain.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::romanization($this->sourceLanguage),
        ]);
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.config('ai.default').'.models.romanization.default');
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
                    'romanization' => $schema->string()->min(1)->required(),
                    'tokens' => $schema->array()
                        ->min(1)
                        ->items($schema->object([
                            'index' => $schema->integer()->min(0)->required(),
                            'romanization' => $schema->string()->min(1)->required(),
                        ])->withoutAdditionalProperties())
                        ->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
