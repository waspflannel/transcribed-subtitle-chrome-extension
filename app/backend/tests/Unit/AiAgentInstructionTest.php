<?php

namespace Tests\Unit;

use App\Ai\Agents\CueAnalysisAgent;
use App\Ai\Agents\CueEnrichmentAgent;
use App\Ai\Agents\CueRomanizationAgent;
use App\Ai\Agents\CueTokenizationAgent;
use App\Ai\Agents\LearningTokenCardAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

class AiAgentInstructionTest extends TestCase
{
    public function test_subtitle_agents_leave_temperature_unset_for_model_compatibility(): void
    {
        foreach ([
            CueAnalysisAgent::class,
            CueEnrichmentAgent::class,
            CueRomanizationAgent::class,
            CueTokenizationAgent::class,
            LearningTokenCardAgent::class,
        ] as $agentClass) {
            $this->assertNull(TextGenerationOptions::forAgent(new $agentClass)->temperature);
        }
    }

    public function test_tokenization_agent_owns_stable_token_boundary_rules(): void
    {
        $instructions = (new CueTokenizationAgent)->instructions();

        $this->assertStringContainsString('Return one tokenized cue for each input cue in the same order.', $instructions);
        $this->assertStringContainsString('Do not return punctuation-only tokens.', $instructions);
        $this->assertStringContainsString('Do not censor profanity', $instructions);
        $this->assertStringContainsString('transcription artifacts', $instructions);
        $this->assertStringContainsString('Every token must begin and end on a word boundary of the source language.', $instructions);

        $this->assertStringContainsString('Orphan fragment', $instructions);
        $this->assertStringContainsString('never strand a single kana that is part of a neighboring content word.', $instructions);
        $this->assertStringContainsString('Truncated word', $instructions);
        $this->assertStringContainsString('Sokuon', $instructions);
        $this->assertStringContainsString('Never drop a leading character to emit うて.', $instructions);

        $this->assertStringContainsString('Mandarin examples:', $instructions);
        $this->assertStringContainsString('Split 我喜欢学习中文 as 我 / 喜欢 / 学习 / 中文', $instructions);
        $this->assertStringContainsString('Thai examples:', $instructions);
        $this->assertStringContainsString('Split ผมชอบกินข้าว as ผม / ชอบ / กิน / ข้าว', $instructions);
    }

    public function test_romanization_agent_owns_stable_romanization_rules(): void
    {
        $instructions = (new CueRomanizationAgent)->instructions();

        $this->assertStringContainsString('Do not translate, retokenize', $instructions);
        $this->assertStringContainsString('Preserve cueId and cue index exactly.', $instructions);
        $this->assertStringContainsString('return the same index', $instructions);
        $this->assertStringContainsString('Do not echo the token text.', $instructions);
        $this->assertStringContainsString('Hepburn for Japanese and pinyin for Mandarin', $instructions);
    }

    public function test_analysis_agent_owns_stable_tokenization_and_translation_rules(): void
    {
        $instructions = (new CueAnalysisAgent)->instructions();

        $this->assertStringContainsString('Return one analyzed cue for each input cue in the same order.', $instructions);
        $this->assertStringContainsString('Do not return punctuation-only tokens.', $instructions);
        $this->assertStringContainsString('Do not censor profanity', $instructions);
        $this->assertStringContainsString('transcription artifacts', $instructions);
        $this->assertStringContainsString('Every token must begin and end on a word boundary of the source language.', $instructions);
        $this->assertStringContainsString('Orphan fragment', $instructions);
        $this->assertStringContainsString('Sokuon', $instructions);
        $this->assertStringContainsString('Mandarin examples:', $instructions);
        $this->assertStringContainsString('Thai examples:', $instructions);
        $this->assertStringContainsString('Translate the intended subtitle meaning', $instructions);
        $this->assertStringContainsString('colloquial, dialectal, romanized, poetic, musical, slang, or idiomatic text', $instructions);
        $this->assertStringContainsString('Use previousCueText and nextCueText', $instructions);
    }

    public function test_enrichment_agent_owns_stable_learning_metadata_rules(): void
    {
        $instructions = (new CueEnrichmentAgent)->instructions();

        $this->assertStringContainsString('Return one enriched cue for each input cue in the same order.', $instructions);
        $this->assertStringContainsString('Return exactly one token for each input token in the same order.', $instructions);
        $this->assertStringContainsString('The cue translation is owned by the server and is NOT part of your output', $instructions);
        $this->assertStringContainsString('Obey includeRomanization from the input.', $instructions);
    }

    public function test_learning_token_card_agent_owns_stable_card_rules(): void
    {
        $instructions = (new LearningTokenCardAgent)->instructions();

        $this->assertStringContainsString('Return exactly one token object for requestedToken.', $instructions);
        $this->assertStringContainsString('requestedToken.text exactly', $instructions);
        $this->assertStringContainsString('For Latin-script languages, omit romanization unless it helps pronunciation.', $instructions);
    }
}
