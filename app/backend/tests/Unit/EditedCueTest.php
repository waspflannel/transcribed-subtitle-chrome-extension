<?php

namespace Tests\Unit;

use App\Ai\Agents\EditedCueAgent;
use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditedCueTest extends TestCase
{
    public function test_refreshes_japanese_words_without_changing_their_boundaries(): void
    {
        EditedCueAgent::fake([$this->agentOutput()])->preventStrayPrompts();
        $cue = $this->refresh();

        $this->assertSame('猫です', $cue['sourceText']);
        $this->assertSame('It is a cat.', $cue['translatedText']);
        $this->assertSame('neko desu', $cue['romanization']);
        $this->assertSame('neko', $cue['tokens'][0]['romanization']);
        $this->assertSame('cat', $cue['tokens'][0]['translation']);
        $this->assertSame('cat', $cue['tokens'][0]['gloss']);
        $this->assertSame(['猫', 'です'], array_column($cue['tokens'], 'text'));
        $this->assertSame(1200, $cue['startMs']);
        $this->assertSame(3000, $cue['endMs']);
    }

    public function test_respects_disabled_layers_but_still_refreshes_word_cards(): void
    {
        EditedCueAgent::fake([$this->agentOutput()]);
        $cue = $this->refresh(translation: false, romanization: false);

        $this->assertSame('猫です', $cue['translatedText']);
        $this->assertArrayNotHasKey('romanization', $cue);
        $this->assertArrayNotHasKey('romanization', $cue['tokens'][0]);
        $this->assertSame('cat', $cue['tokens'][0]['gloss']);
    }

    public function test_same_language_uses_source_for_the_line_translation(): void
    {
        EditedCueAgent::fake([$this->agentOutput()]);
        $this->assertSame('猫です', $this->refresh(target: 'jpn')['translatedText']);
    }

    #[DataProvider('invalidOutputs')]
    public function test_rejects_incomplete_or_rewritten_learning_data(string $path, mixed $value): void
    {
        $output = $this->agentOutput();
        data_set($output, $path, $value);
        EditedCueAgent::fake([$output])->preventStrayPrompts();

        $this->expectException(SubtitleProcessingException::class);
        $this->refresh();
    }

    public static function invalidOutputs(): array
    {
        return [
            'missing translation' => ['translatedText', null],
            'missing cue reading' => ['cues.0.romanization', null],
            'missing token reading' => ['cues.0.tokens.0.romanization', null],
            'missing meaning' => ['cues.0.tokens.0.gloss', null],
            'missing word translation' => ['cues.0.tokens.0.translation', null],
            'rewritten token' => ['cues.0.tokens.0.text', '犬'],
            'retokenized cue' => ['cues.0.tokens', []],
            'wrong cue' => ['cues.0.cueId', 'other-cue'],
        ];
    }

    private function refresh(bool $translation = true, bool $romanization = true, string $target = 'eng'): array
    {
        return app(LaravelAiTranslationAnalysisProvider::class)->refreshEditedCue(
            $this->cue(), 'jpn', $target, $translation, $romanization,
        );
    }

    private function cue(): array
    {
        return [
            'cueId' => 'quick-fix-test', 'index' => 0, 'startMs' => 1200, 'endMs' => 3000,
            'sourceText' => '猫です', 'translatedText' => '猫です',
            'tokens' => [
                ['index' => 0, 'text' => '猫', 'normalizedText' => '猫'],
                ['index' => 1, 'text' => 'です', 'normalizedText' => 'です'],
            ],
        ];
    }

    private function agentOutput(): array
    {
        return [
            'dialect' => 'standard', 'translatedText' => 'It is a cat.',
            'cues' => [[
                ...$this->cue(), 'romanization' => 'neko desu',
                'tokens' => [
                    ['index' => 0, 'text' => '猫', 'translation' => 'cat', 'gloss' => 'cat', 'romanization' => 'neko'],
                    ['index' => 1, 'text' => 'です', 'translation' => 'is', 'gloss' => 'polite copula', 'romanization' => 'desu'],
                ],
            ]],
        ];
    }
}
