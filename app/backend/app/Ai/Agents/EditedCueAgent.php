<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Stringable;

#[MaxTokens(4000)]
class EditedCueAgent extends SubtitleAgent
{
    public function __construct(public readonly ?string $sourceLanguage = null) {}

    public function instructions(): Stringable|string
    {
        return implode("\n\n", [
            'Refresh one subtitle cue after the learner corrected a word or phrase. Return exactly one cue with the same cueId, index, token count, and token indexes. Preserve the supplied token boundaries: a replacement phrase stays one token, even when it contains spaces. The server restores sourceText and token text; do not echo either field. Rebuild every word card from the corrected sentence; do not reuse obsolete meanings.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::WORD_CARD,
            'Return the corrected whole-line translation in top-level translatedText when includeTranslation is true and the languages differ; otherwise copy sourceText there. The nested cue has no translatedText field.',
            'When generating a translation: '.SubtitlePromptRules::TRANSLATION,
            'When includeRomanization is true, regenerate fresh readings for the cue and tokens containing non-Latin letters using the corrected sentence; discard obsolete readings. Return null for Latin-only cue or token romanization. When includeRomanization is false, return null for all romanization. Use "unknown" for dialect when uncertain.',
            SubtitlePromptRules::romanization($this->sourceLanguage),
        ]);
    }

    public function timeout(): int
    {
        return 45;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dialect' => $schema->string()->min(1)->required(),
            'translatedText' => $schema->string()->min(1)->required(),
            'cues' => $schema->array()->min(1)->max(1)->items($schema->object([
                'cueId' => $schema->string()->min(1)->required(),
                'index' => $schema->integer()->min(0)->required(),
                'romanization' => $schema->string()->min(1)->nullable()->required(),
                'tokens' => $schema->array()->min(1)->items($schema->object([
                    ...$this->cardSchema($schema),
                    'romanization' => $schema->string()->min(1)->nullable()->required(),
                ])->withoutAdditionalProperties())->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
