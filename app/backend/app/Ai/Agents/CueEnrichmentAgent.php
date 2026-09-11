<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Stringable;

#[MaxTokens(8000)]
class CueEnrichmentAgent extends SubtitleAgent
{
    public function __construct(public readonly ?string $sourceLanguage = null) {}

    public function instructions(): Stringable|string
    {
        return implode("\n\n", [
            'Create word-card annotations for finalized subtitle cues. Return every cue and token in the supplied order with unchanged cueId, cue index and token index. Token boundaries, text, timings, translations and readings belong to the server. Return only card fields; do not echo or regenerate source text or readings. Use "unknown" for dialect when uncertain.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::WORD_CARD,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dialect' => $schema->string()->min(1)->required(),
            'cues' => $schema->array()->min(1)->items($schema->object([
                'cueId' => $schema->string()->min(1)->required(),
                'index' => $schema->integer()->min(0)->required(),
                'tokens' => $schema->array()->min(1)->items($schema->object($this->cardSchema($schema))->withoutAdditionalProperties())->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
