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
#[MaxTokens(8000)]
class CueEnrichmentAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You enrich finalized, pre-tokenized subtitle cues for a language-to-language subtitle overlay.

Translate each cue into the requested target language and add concise word-card metadata to the provided tokens. Do not change cue IDs, cue indexes, source text, token count, token indexes, or token text. Use "unknown" for dialect when unsure. Use null for optional fields you cannot determine; the application omits nulls before storage.

The tokenizer has already chosen the learner-facing boundaries. Preserve those boundaries exactly. Glosses should be short, romanization readable for learners when requested, and usage notes concise. Return only data that matches the structured output schema.
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
            'dialect' => $schema->string()->min(1)->required(),
            'cues' => $schema->array()
                ->min(1)
                ->items($schema->object([
                    'cueId' => $schema->string()->min(1)->required(),
                    'index' => $schema->integer()->min(0)->required(),
                    'sourceText' => $schema->string()->min(1)->required(),
                    'translatedText' => $schema->string()->min(1)->required(),
                    'romanization' => $schema->string()->min(1)->nullable()->required(),
                    'tokens' => $schema->array()
                        ->min(1)
                        ->items($schema->object([
                            'index' => $schema->integer()->min(0)->required(),
                            'text' => $schema->string()->min(1)->required(),
                            'lemma' => $schema->string()->min(1)->nullable()->required(),
                            'root' => $schema->string()->min(1)->nullable()->required(),
                            'partOfSpeech' => $schema->string()->min(1)->nullable()->required(),
                            'translation' => $schema->string()->min(1)->nullable()->required(),
                            'gloss' => $schema->string()->min(1)->nullable()->required(),
                            'romanization' => $schema->string()->min(1)->nullable()->required(),
                            'usageNote' => $schema->string()->min(1)->nullable()->required(),
                        ])->withoutAdditionalProperties())
                        ->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
