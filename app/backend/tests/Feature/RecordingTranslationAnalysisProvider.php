<?php

namespace Tests\Feature;

use App\Exceptions\SubtitleProcessingException;
use App\Services\TranslationAnalysis\CueAnalysisBatchResult;
use App\Services\TranslationAnalysis\CueEnrichmentResult;
use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;

class RecordingTranslationAnalysisProvider extends LaravelAiTranslationAnalysisProvider
{
    public function __construct()
    {
        parent::__construct(new LearningTokenOutputValidator);
    }

    public int $calls = 0;

    public int $tokenizationCalls = 0;

    public int $romanizationCalls = 0;

    public int $translationCalls = 0;

    public int $tokenCalls = 0;

    public bool $shouldFail = false;

    public bool $tokenizationShouldFail = false;

    public bool $romanizationShouldFail = false;

    public bool $translationShouldFail = false;

    public int $invalidBatchAboveCueCount = 0;

    public ?\Closure $beforeTokenizationResult = null;

    public ?\Closure $beforeTokenResult = null;

    public ?\Closure $beforeRetry = null;

    /**
     * @var array<int, string>
     */
    public array $sourceLanguages = [];

    /**
     * @var array<int, string>
     */
    public array $targetLanguages = [];

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function tokenizeCueBatch(array $batch, array $allCues, string $sourceLanguage, bool $splitInvalidBatches = true, ?\Closure $beforeRetry = null): CueEnrichmentResult
    {
        $this->tokenizationCalls++;
        $this->sourceLanguages[] = $sourceLanguage;

        if (count($batch) > $this->invalidBatchAboveCueCount && $this->invalidBatchAboveCueCount > 0) {
            ($this->beforeRetry)?->__invoke();
            $beforeRetry?->__invoke();
            throw SubtitleProcessingException::enrichmentFailed(context: ['reason' => 'cue_count_mismatch']);
        }

        if ($this->tokenizationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $result = new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'tokens' => $this->tokenizeCue((string) $cue['sourceText'], $sourceLanguage),
                ],
                $batch,
            ),
            'unknown',
        );

        if ($this->beforeTokenizationResult !== null) {
            ($this->beforeTokenizationResult)();
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function enrichCueBatch(
        array $batch,
        string $sourceLanguage,
        string $targetLanguage,
        bool $includeRomanization = true,
        bool $splitInvalidBatches = true,
        ?\Closure $beforeRetry = null,
    ): CueEnrichmentResult {
        $this->calls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                function (array $cue) use ($includeRomanization): array {
                    $enrichedCue = [
                        ...$cue,
                        'translatedText' => (string) ($cue['translatedText'] ?? $cue['sourceText']),
                        'tokens' => array_map(
                            fn (array $token): array => [
                                ...$token,
                                'gloss' => $this->glossForToken((string) $token['text']),
                                ...($includeRomanization && is_string($token['romanization'] ?? null)
                                    ? ['romanization' => $token['romanization']]
                                    : []),
                            ],
                            $cue['tokens'],
                        ),
                    ];

                    if ($includeRomanization && is_string($cue['romanization'] ?? null)) {
                        $enrichedCue['romanization'] = $cue['romanization'];
                    }

                    return $enrichedCue;
                },
                $batch,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<int, array<string, mixed>>  $allCues
     */
    public function analyzeCueBatch(
        array $batch,
        array $allCues,
        string $sourceLanguage,
        string $targetLanguage,
        bool $splitInvalidBatches = true,
        ?\Closure $beforeRetry = null,
    ): CueAnalysisBatchResult {
        $this->tokenizationCalls++;
        $this->translationCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if (count($batch) > $this->invalidBatchAboveCueCount && $this->invalidBatchAboveCueCount > 0) {
            ($this->beforeRetry)?->__invoke();
            $beforeRetry?->__invoke();
            throw SubtitleProcessingException::enrichmentFailed(context: ['reason' => 'cue_count_mismatch']);
        }

        if ($this->tokenizationShouldFail || $this->translationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $result = new CueAnalysisBatchResult(
            new CueEnrichmentResult(
                array_map(
                    fn (array $cue): array => [
                        ...$cue,
                        'translatedText' => (string) $cue['sourceText'],
                        'tokens' => $this->tokenizeCue((string) $cue['sourceText'], $sourceLanguage),
                    ],
                    $batch,
                ),
                'unknown',
            ),
            new CueEnrichmentResult(
                array_map(
                    fn (array $cue): array => [
                        ...$cue,
                        'translatedText' => 'Translated '.$cue['sourceText'],
                    ],
                    $batch,
                ),
                'unknown',
            ),
        );

        if ($this->beforeTokenizationResult !== null) {
            ($this->beforeTokenizationResult)();
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    public function romanizeCueBatch(array $batch, string $sourceLanguage): CueEnrichmentResult
    {
        $this->romanizationCalls++;

        if ($this->romanizationShouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        return new CueEnrichmentResult(
            array_map(
                fn (array $cue): array => [
                    ...$cue,
                    'translatedText' => (string) $cue['sourceText'],
                    'romanization' => $this->romanizationForCue((string) $cue['sourceText'], $sourceLanguage),
                    'tokens' => array_map(
                        fn (array $token): array => [
                            ...$token,
                            'romanization' => $this->romanizationForToken((string) $token['text']),
                        ],
                        $cue['tokens'],
                    ),
                ],
                $batch,
            ),
            'unknown',
        );
    }

    /**
     * @param  array<string, mixed>  $cue
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    public function enrichToken(array $cue, array $token, string $sourceLanguage, string $targetLanguage): array
    {
        $this->tokenCalls++;
        $this->sourceLanguages[] = $sourceLanguage;
        $this->targetLanguages[] = $targetLanguage;

        if ($this->shouldFail) {
            throw SubtitleProcessingException::enrichmentFailed();
        }

        $text = (string) $token['text'];

        if ($this->beforeTokenResult !== null) {
            ($this->beforeTokenResult)();
        }

        return [
            'index' => $token['index'],
            'text' => $text,
            'normalizedText' => $token['normalizedText'],
            'gloss' => $text.' gloss',
            'romanization' => $text.' romanized',
            'usageNote' => 'Clicked token from cue '.$cue['cueId'].'.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tokenizeCue(string $sourceText, string $sourceLanguage): array
    {
        if ($sourceLanguage === 'jpn') {
            $comparableText = $this->comparableText($sourceText);

            if ($comparableText === "\u{79C1}\u{306F}\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}") {
                return $this->japaneseLearningTokens();
            }

            if ($comparableText === "\u{306D}\u{3048}\u{4ECA}\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}") {
                return [
                    ['index' => 0, 'text' => "\u{306D}\u{3048}", 'normalizedText' => "\u{306D}\u{3048}"],
                    ['index' => 1, 'text' => "\u{4ECA}", 'normalizedText' => "\u{4ECA}"],
                    ['index' => 2, 'text' => "\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}", 'normalizedText' => "\u{601D}\u{3063}\u{3066}\u{3044}\u{3066}"],
                    ['index' => 3, 'text' => "\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}", 'normalizedText' => "\u{3042}\u{305D}\u{3046}\u{3058}\u{3083}\u{306A}"],
                ];
            }
        }

        $words = array_values(array_filter(
            preg_split('/\s+/u', trim($sourceText)) ?: [],
            fn (string $word): bool => $word !== '',
        ));

        if ($words === []) {
            $words = [$sourceText];
        }

        return array_map(
            fn (string $word, int $index): array => [
                'index' => $index,
                'text' => $word,
                'normalizedText' => strtolower($word),
            ],
            $words,
            array_keys($words),
        );
    }

    private function glossForToken(string $token): string
    {
        return match ($token) {
            "\u{65E5}\u{672C}\u{8A9E}" => 'Japanese language',
            "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}" => 'am studying',
            default => $token,
        };
    }

    private function romanizationForCue(string $sourceText, string $sourceLanguage): string
    {
        return $sourceLanguage === 'jpn' && $this->comparableText($sourceText) === "\u{79C1}\u{306F}\u{65E5}\u{672C}\u{8A9E}\u{3092}\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}"
            ? 'watashi wa nihongo o benkyo shite imasu'
            : 'romanized '.$sourceText;
    }

    private function romanizationForToken(string $token): string
    {
        return match ($token) {
            "\u{79C1}" => 'watashi',
            "\u{306F}" => 'wa',
            "\u{65E5}\u{672C}\u{8A9E}" => 'nihongo',
            "\u{3092}" => 'o',
            "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}" => 'benkyo shite imasu',
            default => 'romanized '.$token,
        };
    }

    private function comparableText(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function japaneseLearningTokens(): array
    {
        return [
            ['index' => 0, 'text' => "\u{79C1}", 'normalizedText' => "\u{79C1}"],
            ['index' => 1, 'text' => "\u{306F}", 'normalizedText' => "\u{306F}"],
            ['index' => 2, 'text' => "\u{65E5}\u{672C}\u{8A9E}", 'normalizedText' => "\u{65E5}\u{672C}\u{8A9E}"],
            ['index' => 3, 'text' => "\u{3092}", 'normalizedText' => "\u{3092}"],
            ['index' => 4, 'text' => "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}", 'normalizedText' => "\u{52C9}\u{5F37}\u{3057}\u{3066}\u{3044}\u{307E}\u{3059}"],
        ];
    }
}
