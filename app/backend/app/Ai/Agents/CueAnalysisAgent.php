<?php

namespace App\Ai\Agents;

use App\Exceptions\SubtitleProcessingException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Chooses source tokens and supplies requested translation and romanization.
 */
#[Provider(Lab::OpenAI)]
#[MaxTokens(9000)]
class CueAnalysisAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public readonly bool $includeTranslation = true,
        public readonly bool $includeRomanization = false,
    ) {}

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === Lab::OpenAI || $provider === Lab::OpenAI->value
            ? config('ai.providers.openai.provider_options', [])
            : [];
    }

    public function instructions(): Stringable|string
    {
        $instructions = <<<'INSTRUCTIONS'
Tokenize and translate finalized transcript cues for a language-learning subtitle overlay.

Return one analyzed cue for each input cue in the same order. Preserve cueId and cue index exactly. Each output cue carries source-language token boundaries. Translate only when includeTranslation is true. Romanize only when includeRomanization is true. Do not explain grammar or create learner-card metadata.

Tokenization rules:

Return only source-language token boundaries. Token text must preserve source characters in source order. Token indexes must be zero-based and sequential within each cue. Do not return punctuation-only tokens.

Each token text must be copied from a contiguous substring of sourceText after the previous token. Do not censor profanity, normalize apostrophes or dashes, expand contractions, correct spelling, rewrite slang, or replace transcript words with safer wording. If a source word is offensive or malformed, copy the source characters exactly.

Use the language's normal learner segmentation. Prefer one learner-clickable lexical unit per token. For space-delimited text, keep natural learner words or short fixed phrases. For no-space scripts, choose meaningful words or short phrases rather than individual characters or arbitrary chunks. If the transcript inserted spaces between individual characters in a no-space script, treat those spaces as transcription artifacts and group the underlying source characters into learner units.

Keep particles, case markers, short connectors, and auxiliaries separate when they function independently. Do not attach a leading or trailing function word to a neighboring content word. Avoid broad phrase chunks unless the expression is fixed and useful as one card.

Every token must begin and end on a word boundary of the source language. Never start a token in the middle of a content word, and never leave a single function-word fragment of a content word as its own token.

Japanese examples:
- Split か聞いてみた as か / 聞いて / みた. Never return か聞いてみた as one token.
- Split みたいと as みたい / と. Never return いと.
- Orphan fragment: never strand a single kana that is part of a neighboring content word. Split なり大事な友達 as なり / 大事 / な / 友達. Never return the trailing り of なり as its own token.
- Truncated word: keep a conjugated ending attached to its stem. Split ならなくちゃ as ならなくちゃ. Never cut to ならなく and drop the ending.
- Sokuon: when source contains 打って, keep 打って whole (or 打っ / て only if that is the learner unit). Never drop a leading character to emit うて.

Mandarin examples:
- Split 我喜欢学习中文 as 我 / 喜欢 / 学习 / 中文 (pronoun / verb / verb / noun). Never return 我喜欢 as one token, and never split a two-character word like 喜欢 or 学习 into single characters.
- Split 这是一个很好的例子 as 这 / 是 / 一个 / 很 / 好 / 的 / 例子. Keep 一个 together, keep 例子 together, and keep 的 separate as a particle.

Thai examples:
- Split ผมชอบกินข้าว as ผม / ชอบ / กิน / ข้าว (I / like / eat / rice). Group characters into dictionary words; never split a syllable across tokens and never return single characters.

English-like example: do not return "go to the store today" as one token. Words or short fixed expressions are acceptable.

Translation rules (only when includeTranslation is true):

Return a natural non-empty translatedText for each cue in the requested target language. Translate the intended subtitle meaning, not isolated word labels or dictionary glosses. For colloquial, dialectal, romanized, poetic, musical, slang, or idiomatic text, preserve the speaker's intent, tone, and implied meaning in natural target-language subtitle phrasing. Use previousCueText and nextCueText when provided to resolve ambiguous words or phrases. If a literal reading conflicts with the surrounding subtitle or lyric context, choose the contextual meaning.

Use "unknown" for dialect when it cannot be detected.

Prefer token boundaries a beginner can tap for a useful word card. Return only data that matches the structured output schema.
INSTRUCTIONS;

        if ($this->includeRomanization) {
            $instructions .= "\nRomanization rules:\nAfter choosing the source token boundaries, return readable learner-standard Latin-script pronunciation for the whole cue and for each of those exact tokens. Use Hepburn for Japanese and pinyin for Mandarin. Use cue and neighboring context to resolve ambiguous readings. Do not change source text or token boundaries to fit a romanization.\n";
        }

        return $instructions;
    }

    public function model(): string
    {
        $model = config('ai.providers.'.Lab::OpenAI->value.'.models.analysis.default');

        if (! is_string($model) || trim($model) === '') {
            throw SubtitleProcessingException::enrichmentFailed('Subtitle AI model is not configured.', [
                'provider' => Lab::OpenAI->value,
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
