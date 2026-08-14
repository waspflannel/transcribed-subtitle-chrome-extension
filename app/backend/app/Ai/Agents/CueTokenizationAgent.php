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
#[MaxTokens(5000)]
class CueTokenizationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
Tokenize finalized transcript cues for a language-learning subtitle overlay.

Return one tokenized cue for each input cue in the same order. Return only source-language token boundaries. Do not include source text, romanization, translations, glosses, grammar metadata, or learner-card metadata. Preserve cueId and cue index exactly. Token text must preserve source characters in source order. Token indexes must be zero-based and sequential within each cue. Do not return punctuation-only tokens.

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

Use "unknown" for dialect when it cannot be detected.

Prefer boundaries a beginner can tap for a useful word card. Return only data that matches the structured output schema.
INSTRUCTIONS;
    }

    public function model(): string
    {
        return (string) config('ai.providers.'.Lab::OpenAI->value.'.models.tokenization.default');
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
                    'tokens' => $schema->array()
                        ->min(1)
                        ->items($schema->object([
                            'index' => $schema->integer()->min(0)->required(),
                            'text' => $schema->string()->min(1)->required(),
                        ])->withoutAdditionalProperties())
                        ->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
