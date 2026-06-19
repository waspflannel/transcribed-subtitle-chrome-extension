<?php

namespace App\Console\Commands;

use App\Services\TranslationAnalysis\LaravelAiTranslationAnalysisProvider;
use App\Services\TranslationAnalysis\LearningTokenOutputValidator;
use App\Services\TranslationAnalysis\SegmentationEvaluation;
use App\Services\TranslationAnalysis\TokenizationBoundaryMetric;
use App\Services\TranslationAnalysis\TokenizationQualitySummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Laravel\Ai\Enums\Lab;
use Throwable;

#[Signature('subtitles:eval-tokenization {--lang=all : Language code (jpn, cmn, tha, or all)} {--model= : Override the tokenization model for this run} {--json : Output machine-readable JSON} {--out= : Write JSON results to this path}')]
#[Description('Score the tokenization agent against CJK gold fixtures using boundary/word F1 and failure-mode attribution.')]
class EvalTokenization extends Command
{
    private const FIXTURE_DIR = 'tests/Fixtures/tokenization';

    /** @var array<int, string> */
    private const LANGUAGES = ['jpn', 'cmn', 'tha'];

    private TokenizationBoundaryMetric $metric;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->openAiKeyConfigured()) {
            $this->components->error('OPENAI_API_KEY is not configured. The eval harness makes live tokenization calls and cannot run without it.');

            return self::FAILURE;
        }

        $languages = $this->languages();
        $model = $this->modelOverride();
        $provider = $this->laravel->make(LaravelAiTranslationAnalysisProvider::class);
        $this->metric = new TokenizationBoundaryMetric(new LearningTokenOutputValidator);

        $results = [];

        foreach ($languages as $lang) {
            $fixtures = $this->loadFixtures($lang);

            if ($fixtures === []) {
                $this->components->warn("No fixtures found for [{$lang}].");

                continue;
            }

            $results[$lang] = $this->scoreLanguage($provider, $lang, $fixtures);
        }

        $payload = $this->payload($results, $model);

        if (is_string($this->option('out'))) {
            $this->writeJsonFile($this->option('out'), $payload);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTables($payload);

        return self::SUCCESS;
    }

    private function openAiKeyConfigured(): bool
    {
        return is_string(config('ai.providers.openai.key')) && trim((string) config('ai.providers.openai.key')) !== '';
    }

    private function modelOverride(): ?string
    {
        $model = trim((string) $this->option('model'));

        if ($model === '') {
            return null;
        }

        config()->set('ai.providers.'.Lab::OpenAI->value.'.models.tokenization.default', $model);

        return $model;
    }

    /**
     * @return list<string>
     */
    private function languages(): array
    {
        $lang = trim((string) $this->option('lang'));

        if ($lang === '' || $lang === 'all') {
            return self::LANGUAGES;
        }

        if (! in_array($lang, self::LANGUAGES, true)) {
            $this->components->error("Unsupported language [{$lang}]. Supported: jpn, cmn, tha, all.");

            exit(self::FAILURE);
        }

        return [$lang];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadFixtures(string $lang): array
    {
        $path = base_path(self::FIXTURE_DIR.'/'.$lang.'.json');

        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return [];
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fixtures
     * @return array<string, mixed>
     */
    private function scoreLanguage(LaravelAiTranslationAnalysisProvider $provider, string $lang, array $fixtures): array
    {
        $perCue = [];

        foreach ($fixtures as $fixture) {
            $perCue[] = $this->scoreFixture($provider, $lang, $fixture);
        }

        $evaluations = array_values(array_filter(
            $perCue,
            fn (array $row) => $row['evaluation'] instanceof SegmentationEvaluation,
        ));

        $summary = $this->metric->aggregate($lang, array_map(
            fn (array $row) => $row['evaluation'],
            $evaluations,
        ));

        return [
            'summary' => $summary,
            'cues' => $perCue,
        ];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function scoreFixture(LaravelAiTranslationAnalysisProvider $provider, string $lang, array $fixture): array
    {
        $id = (string) ($fixture['id'] ?? '');
        $sourceText = (string) ($fixture['sourceText'] ?? '');
        $goldTokens = array_values(array_filter(
            array_map('strval', (array) ($fixture['goldTokens'] ?? [])),
            fn (string $token) => $token !== '',
        ));

        try {
            $predictedTokens = $this->predictedTokens($provider, $lang, $id, $sourceText);
        } catch (Throwable $exception) {
            return [
                'id' => $id,
                'lang' => $lang,
                'status' => 'provider_error',
                'sourceText' => $sourceText,
                'goldTokens' => $goldTokens,
                'predictedTokens' => [],
                'evaluation' => null,
                'error' => $exception->getMessage(),
            ];
        }

        $evaluation = $this->metric->evaluate(
            $id,
            $lang,
            $sourceText,
            $goldTokens,
            $predictedTokens,
            isset($fixture['note']) ? (string) $fixture['note'] : null,
        );

        return [
            'id' => $id,
            'lang' => $lang,
            'status' => $evaluation->transcriptionFault ? 'transcription_fault' : 'scored',
            'sourceText' => $sourceText,
            'goldTokens' => $goldTokens,
            'predictedTokens' => $predictedTokens,
            'evaluation' => $evaluation,
            'error' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function predictedTokens(LaravelAiTranslationAnalysisProvider $provider, string $lang, string $cueId, string $sourceText): array
    {
        $cue = [
            'cueId' => $cueId,
            'index' => 0,
            'startMs' => 0,
            'endMs' => 0,
            'sourceText' => $sourceText,
        ];

        $result = $provider->tokenizeCueBatch([$cue], [$cue], $lang);
        $cues = $result->cues;
        $tokens = $cues[0]['tokens'] ?? [];

        return array_values(array_map(
            fn (array $token) => (string) $token['text'],
            array_values($tokens),
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function payload(array $results, ?string $model): array
    {
        return [
            'model' => $model ?? (string) config('ai.providers.'.Lab::OpenAI->value.'.models.tokenization.default'),
            'languages' => array_map(
                fn (array $result): array => $this->languagePayload($result),
                $results,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function languagePayload(array $result): array
    {
        /** @var TokenizationQualitySummary $summary */
        $summary = $result['summary'];

        return [
            'summary' => $this->summaryPayload($summary),
            'cues' => array_map(fn (array $row) => $this->cuePayload($row), $result['cues']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryPayload(TokenizationQualitySummary $summary): array
    {
        return [
            'lang' => $summary->lang,
            'totalCues' => $summary->totalCues,
            'scoredCues' => $summary->scoredCues,
            'transcriptionFaultCues' => $summary->transcriptionFaultCues,
            'boundary' => $this->ratesPayload($summary->boundaryPrecision, $summary->boundaryRecall, $summary->boundaryF1, $summary->boundaryTruePositives, $summary->predictedBoundaries, $summary->goldBoundaries),
            'word' => $this->ratesPayload($summary->wordPrecision, $summary->wordRecall, $summary->wordF1, $summary->correctWords, $summary->predictedWords, $summary->goldWords),
            'failureModes' => [
                'goldWordSplits' => $summary->goldWordSplits,
                'orphanFragments' => $summary->orphanFragments,
                'truncatedWords' => $summary->truncatedWords,
                'lostCharacters' => $summary->lostCharacters,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function cuePayload(array $row): array
    {
        $evaluation = $row['evaluation'];

        if (! $evaluation instanceof SegmentationEvaluation) {
            return Arr::only($row, ['id', 'lang', 'status', 'sourceText', 'goldTokens', 'predictedTokens', 'error']);
        }

        return [
            'id' => $evaluation->id,
            'lang' => $evaluation->lang,
            'status' => $row['status'],
            'sourceText' => $evaluation->sourceText,
            'goldTokens' => $evaluation->goldTokens,
            'predictedTokens' => $evaluation->predictedTokens,
            'note' => $evaluation->note,
            'boundary' => $this->ratesPayload(
                $this->metricRate($evaluation->truePositiveBoundaries, $evaluation->predictedBoundaryCount),
                $this->metricRate($evaluation->truePositiveBoundaries, $evaluation->goldBoundaryCount),
                $this->metricF1($evaluation->truePositiveBoundaries, $evaluation->predictedBoundaryCount, $evaluation->goldBoundaryCount),
                $evaluation->truePositiveBoundaries,
                $evaluation->predictedBoundaryCount,
                $evaluation->goldBoundaryCount,
            ),
            'failureModes' => [
                'goldWordSplits' => $evaluation->goldWordSplits,
                'orphanFragments' => $evaluation->orphanFragments,
                'truncatedWords' => $evaluation->truncatedWords,
                'lostCharacters' => $evaluation->lostCharacters,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ratesPayload(float $precision, float $recall, float $f1, int $truePositives, int $predictedCount, int $goldCount): array
    {
        return [
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1' => round($f1, 4),
            'truePositives' => $truePositives,
            'predicted' => $predictedCount,
            'gold' => $goldCount,
        ];
    }

    private function metricRate(int $truePositives, int $count): float
    {
        return $count > 0 ? $truePositives / $count : 0.0;
    }

    private function metricF1(int $truePositives, int $predictedCount, int $goldCount): float
    {
        $precision = $this->metricRate($truePositives, $predictedCount);
        $recall = $this->metricRate($truePositives, $goldCount);

        return $precision + $recall > 0.0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJsonFile(string $path, array $payload): void
    {
        $directory = dirname($path);

        if ($directory !== '' && ! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->components->info("Wrote eval results to [{$path}].");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderTables(array $payload): void
    {
        $model = (string) $payload['model'];

        $this->components->info("Tokenization eval (model: {$model})");

        foreach ($payload['languages'] as $language) {
            $this->renderLanguageTable($language);
        }
    }

    /**
     * @param  array<string, mixed>  $language
     */
    private function renderLanguageTable(array $language): void
    {
        $summary = $language['summary'];

        $this->table(
            ['metric', 'value'],
            [
                ['lang', $summary['lang']],
                ['scored / total cues', "{$summary['scoredCues']} / {$summary['totalCues']}"],
                ['transcription-fault cues', (string) $summary['transcriptionFaultCues']],
                ['boundary F1 (P / R)', sprintf('%.3f (%.3f / %.3f)', $summary['boundary']['f1'], $summary['boundary']['precision'], $summary['boundary']['recall'])],
                ['word F1 (P / R)', sprintf('%.3f (%.3f / %.3f)', $summary['word']['f1'], $summary['word']['precision'], $summary['word']['recall'])],
                ['gold-word splits', (string) $summary['failureModes']['goldWordSplits']],
                ['orphan fragments', (string) $summary['failureModes']['orphanFragments']],
                ['truncated words', (string) $summary['failureModes']['truncatedWords']],
                ['lost characters (transcription)', (string) $summary['failureModes']['lostCharacters']],
            ],
        );

        $rows = [];

        foreach ($language['cues'] as $cue) {
            $rows[] = [
                $cue['id'],
                $cue['status'],
                $cue['sourceText'],
                $this->formatTokenList($cue['goldTokens'] ?? []),
                $this->formatTokenList($cue['predictedTokens'] ?? []),
                $cue['error'] ?? '',
            ];
        }

        $this->table(['id', 'status', 'source', 'gold', 'predicted', 'error'], $rows);
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function formatTokenList(array $tokens): string
    {
        return implode(' / ', $tokens);
    }
}
