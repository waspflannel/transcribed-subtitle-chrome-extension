<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Stringable;

#[MaxTokens(1200)]
class LearningTokenCardAgent extends SubtitleAgent
{
    public function __construct(public readonly ?string $sourceLanguage = null) {}

    public function instructions(): Stringable|string
    {
        return implode("\n\n", [
            'Create one learner card for requestedToken in its source sentence. Preserve requestedToken.index, including nonzero indexes. Explain the supplied word or phrase without splitting it. Return only card annotations. The server owns token text and readings.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::WORD_CARD,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['token' => $schema->object($this->cardSchema($schema))->withoutAdditionalProperties()->required()];
    }
}
