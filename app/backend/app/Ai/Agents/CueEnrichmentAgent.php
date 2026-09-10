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

#[MaxTokens(8000)]
class CueEnrichmentAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
            'Enrich finalized, pre-tokenized subtitle cues. Return one enriched cue for each input cue in the same order. Preserve cueId and cue index exactly. Return exactly one token for each input token in the same order, preserving its index and boundaries. The server restores sourceText and token text; do not echo either field. The cue translation is supporting context and is not part of your output.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::WORD_CARD,
            'Obey includeRomanization from the input. When false, return null for cue and token romanization. When true, preserve supplied non-empty readings exactly and generate missing readings only for text containing non-Latin letters; leave missing Latin-only readings null. Use the provided readings to keep whole-cue and token readings consistent. Use "unknown" for dialect when uncertain.',
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
            'dialect' => $schema->string()->min(1)->required(),
            'cues' => $schema->array()
                ->min(1)
                ->items($schema->object([
                    'cueId' => $schema->string()->min(1)->required(),
                    'index' => $schema->integer()->min(0)->required(),
                    'romanization' => $schema->string()->min(1)->nullable()->required(),
                    'tokens' => $schema->array()
                        ->min(1)
                        ->items($schema->object([
                            'index' => $schema->integer()->min(0)->required(),
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
