<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use Tests\TestCase;

class DeterministicRomanizationTest extends TestCase
{
    public function test_cyrillic_source_is_romanized_with_icu_and_no_llm_call(): void
    {
        // No AI backend is configured in tests, so reaching the LLM path would
        // throw. Producing output therefore proves the deterministic route was
        // taken -- zero provider calls -- and that the ICU output is correct.
        $result = $this->provider()->romanizeCueBatch([
            $this->tokenizedCue('cue-0', 'Привет мир', [
                ['index' => 0, 'text' => 'Привет', 'normalizedText' => 'привет'],
                ['index' => 1, 'text' => 'мир', 'normalizedText' => 'мир'],
            ]),
        ], 'rus');

        $cue = $result->cues[0];
        $this->assertSame('Privet mir', $cue['romanization']);
        $this->assertSame('Privet', $cue['tokens'][0]['romanization']);
        $this->assertSame('mir', $cue['tokens'][1]['romanization']);

        // The source token identity is preserved exactly as the LLM path does.
        $this->assertSame(0, $cue['tokens'][0]['index']);
        $this->assertSame('Привет', $cue['tokens'][0]['text']);
        $this->assertSame('привет', $cue['tokens'][0]['normalizedText']);
    }

    public function test_greek_source_is_romanized_with_icu(): void
    {
        $result = $this->provider()->romanizeCueBatch([
            $this->tokenizedCue('cue-0', 'Γειά σου', [
                ['index' => 0, 'text' => 'Γειά', 'normalizedText' => 'γειά'],
                ['index' => 1, 'text' => 'σου', 'normalizedText' => 'σου'],
            ]),
        ], 'ell');

        $this->assertSame('Geia sou', $result->cues[0]['romanization']);
    }

    public function test_a_misconfigured_transliterator_id_fails_loudly(): void
    {
        // A bad ICU id is a deploy error; it must not silently revert to a
        // billed LLM call.
        config(['subtitles.romanization.deterministic' => ['rus' => 'Not-A-Real-Transliterator']]);

        $this->expectException(SubtitleProcessingException::class);

        $this->provider()->romanizeCueBatch([
            $this->tokenizedCue('cue-0', 'Привет', [
                ['index' => 0, 'text' => 'Привет', 'normalizedText' => 'привет'],
            ]),
        ], 'rus');
    }

    private function provider(): LaravelAiTranslationAnalysisProvider
    {
        return app(LaravelAiTranslationAnalysisProvider::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tokens
     * @return array<string, mixed>
     */
    private function tokenizedCue(string $cueId, string $sourceText, array $tokens): array
    {
        return [
            'cueId' => $cueId,
            'index' => 0,
            'sourceText' => $sourceText,
            'translatedText' => '',
            'tokens' => $tokens,
        ];
    }
}
