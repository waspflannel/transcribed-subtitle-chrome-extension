<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Chooses source tokens and supplies requested translation and romanization.
 */
#[MaxTokens(9000)]
class CueAnalysisAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public readonly bool $includeTranslation = true,
        public readonly bool $includeRomanization = false,
        public readonly ?string $sourceLanguage = null,
    ) {}

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::OpenAI || $provider === Lab::OpenAI->value
            ? config('ai.providers.openai.provider_options', [])
            : [];
    }

    public function instructions(): Stringable|string
    {
        $instructions = [
            'Analyze finalized transcript cues for a language-learning subtitle overlay. Return one analyzed cue for each input cue in the same order. Preserve cueId and cue index exactly. Return source-language token boundaries and only the requested translation and romanization fields. Do not create learner-card metadata. Use "unknown" for dialect when uncertain.',
            SubtitlePromptRules::TEXT_IS_DATA,
            SubtitlePromptRules::segmentation($this->sourceLanguage),
        ];

        if ($this->includeTranslation) {
            $instructions[] = SubtitlePromptRules::TRANSLATION;
        }

        if ($this->includeRomanization) {
            $instructions[] = 'After choosing token boundaries, return non-empty romanization for the whole cue and every token. For tokens containing only Latin letters, digits, or symbols, copy their text into the required romanization field.';
            $instructions[] = SubtitlePromptRules::romanization($this->sourceLanguage);
        }

        return implode("\n\n", $instructions);
    }

    public function model(): string
    {
        $model = config('ai.providers.'.config('ai.default').'.models.analysis.default');

        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.', [
                'provider' => config('ai.default'),
                'adapter' => 'laravel-ai-sdk',
                'model_key' => 'analysis.default',
            ]);
        }

        return trim($model);
    }

    public function timeout(): int
    {
        return (int) config('subtitles.enrichment.timeout_seconds', 120);
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
            'dialect' => $schema->string()->min(1)->required(),
            'cues' => $schema->array()
                ->min(1)
                ->items($schema->object($cue)->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
