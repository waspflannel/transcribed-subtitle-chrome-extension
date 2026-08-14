<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[MaxTokens(8000)]
class CueEnrichmentAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You enrich finalized, pre-tokenized subtitle cues for a language-to-language subtitle overlay.

Return one enriched cue for each input cue in the same order. The cue translation is owned by the server and is NOT part of your output; add only concise word-card metadata to the provided tokens. Do not change cue IDs, cue indexes, source text, token count, token indexes, or token text. Return exactly one token for each input token in the same order.

The tokenizer has already chosen the learner-facing boundaries. Preserve those boundaries exactly. Add short gloss or translation metadata for the target language. Add concise usage notes only when useful. Leave lemma, root, and partOfSpeech null unless useful. Use null for optional fields you cannot determine; the application omits nulls before storage.

Obey includeRomanization from the input. If includeRomanization is true, preserve provided romanization and add learner-standard romanization when useful, such as Hepburn for Japanese and pinyin for Mandarin. If includeRomanization is false, set cue and token romanization to null. Use "unknown" for dialect when unsure. Return only data that matches the structured output schema.
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
