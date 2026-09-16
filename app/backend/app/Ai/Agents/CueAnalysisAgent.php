<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Stringable;

#[MaxTokens(16000)]
class CueAnalysisAgent extends SubtitleAgent
{
    public function __construct(
        public readonly ?string $sourceLanguage = null,
        public readonly bool $includeTranslation = true,
        public readonly bool $includeRomanization = false,
    ) {}

    public function instructions(): Stringable|string
    {
        $rules = [
            'Analyze fixed transcript cues for a language-learning subtitle overlay. Return one cue for each input cue in the same order, preserving cueId and index. Return learner tokens and only the requested translation and pronunciation. Do not return grammar or word-card metadata. Use the other cues and contextCues only to resolve meaning; do not move content between cues.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::segmentation($this->sourceLanguage),
        ];
        if ($this->includeTranslation) {
            $rules[] = SubtitlePromptRules::TRANSLATION;
        }
        if ($this->includeRomanization) {
            $rules[] = 'Return a non-empty romanization for the whole cue and every returned lexical token. '.SubtitlePromptRules::romanization($this->sourceLanguage);
        }

        return implode("\n\n", $rules);
    }

    public function schema(JsonSchema $schema): array
    {
        $token = [
            'index' => $schema->integer()->min(0)->required(),
            'text' => $schema->string()->min(1)->required(),
        ];
        $cue = [
            'cueId' => $schema->string()->min(1)->required(),
            'index' => $schema->integer()->min(0)->required(),
        ];

        if ($this->includeTranslation) {
            $cue['translatedText'] = $schema->string()->min(1)->required();
        }

        if ($this->includeRomanization) {
            $cue['romanization'] = $schema->string()->min(1)->required();
            $token['romanization'] = $schema->string()->min(1)->required();
        }

        $cue['tokens'] = $schema->array()->min(1)
            ->items($schema->object($token)->withoutAdditionalProperties())->required();

        return [
            'cues' => $schema->array()
                ->min(1)
                ->items($schema->object($cue)->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
