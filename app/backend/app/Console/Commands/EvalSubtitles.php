<?php

namespace App\Console\Commands;

use App\Ai\Agents\TranslationJudgeAgent;
use App\Models\CachedVideoTranscript;
use App\Models\SubtitleTrack;
use App\Services\InstanceSettings;
use App\Services\TranslationAnalysis\TokenizationBoundaryMetric;
use App\Services\TranslationAnalysis\TranscriptAccuracyMetric;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class EvalSubtitles extends Command
{
    protected $signature = 'subtitles:eval {--gold= : Gold JSON path} {--job= : Score this saved job only} {--cached : Score cached transcripts instead of saved tracks} {--judge : Make paid translation judge calls} {--json : Print JSON only} {--out= : Save JSON report}';

    protected $description = 'Score saved subtitle output against a human-checked gold set without generating new output.';

    public function handle(TranscriptAccuracyMetric $accuracy, TokenizationBoundaryMetric $boundaries): int
    {
        try {
            $path = $this->option('gold') ?: base_path('tests/Fixtures/gold/synthetic.json');
            $entries = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
                throw new InvalidArgumentException('Gold set must be a nonempty JSON array.');
            }
            if ($this->option('judge') && ! in_array(config('ai.default'), ['openai', 'cerebras', 'anthropic'], true)) {
                throw new InvalidArgumentException('The optional judge requires a configured direct API provider.');
            }
            $results = [];
            foreach ($entries as $gold) {
                if (! is_array($gold) || ! preg_match('/^[A-Za-z0-9_-]{11}$/', $gold['videoId'] ?? '')
                    || ! is_string($gold['language'] ?? null) || ! is_string($gold['transcript'] ?? null)
                    || isset($gold['tokens']) && (! is_array($gold['tokens']) || array_filter($gold['tokens'], fn ($token) => ! is_string($token)))) {
                    throw new InvalidArgumentException('Each gold entry needs videoId, language, transcript and optional string tokens.');
                }
                $candidates = $this->candidates($gold);
                if ($candidates === []) {
                    $results[] = ['videoId' => $gold['videoId'], 'status' => 'missing'];
                }
                foreach ($candidates as $candidate) {
                    $result = ['videoId' => $gold['videoId'], 'language' => $gold['language'], 'status' => 'scored', ...$candidate['metadata'],
                        ...$accuracy->score($gold['transcript'], $candidate['text'], $gold['language'])];
                    if (isset($gold['tokens']) && $candidate['tokens'] !== []) {
                        $metric = $boundaries->evaluate($gold['videoId'], $gold['language'], $gold['transcript'], $gold['tokens'], $candidate['tokens']);
                        $denominator = $metric->predictedBoundaryCount + $metric->goldBoundaryCount;
                        $result['boundaryF1'] = $denominator === 0 ? 1.0 : 2 * $metric->truePositiveBoundaries / $denominator;
                        $result['unlocatablePredictedTokens'] = $metric->unlocatablePredictedTokens;
                    }
                    if ($this->option('judge') && isset($gold['translation']) && $candidate['translation'] !== '') {
                        app(InstanceSettings::class)->apply();
                        $response = TranslationJudgeAgent::make()->prompt(json_encode(['reference' => $gold['translation'], 'candidate' => $candidate['translation']], JSON_THROW_ON_ERROR));
                        $score = $response->toArray()['score'] ?? null;
                        if (! is_int($score) || $score < 0 || $score > 100) {
                            throw new InvalidArgumentException('Translation judge returned an invalid score.');
                        }
                        $result['translationScore'] = $score;
                    }
                    $results[] = $result;
                }
            }
            $report = ['normalization' => 'NFKC, lowercase, remove punctuation, collapse whitespace; CER removes whitespace', 'judge' => (bool) $this->option('judge'), 'results' => $results];
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($this->option('out')) {
                file_put_contents($this->option('out'), $json."\n");
            }
            if (! $this->option('json')) {
                $this->table(['video', 'status', 'metric', 'error rate', 'boundary F1'], array_map(fn ($row) => [$row['videoId'], $row['status'], $row['metric'] ?? '-', $row['errorRate'] ?? '-', $row['boundaryF1'] ?? '-'], $results));
            }
            $this->line($json);

            return collect($results)->contains('status', 'missing') ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function candidates(array $gold): array
    {
        if ($this->option('cached')) {
            if ($this->option('job')) {
                throw new InvalidArgumentException('--job cannot be combined with --cached.');
            }

            return CachedVideoTranscript::query()->where('youtube_video_id', $gold['videoId'])->get()
                ->filter(fn ($entry) => ($entry->payload['language'] ?? null) === $gold['language'])
                ->map(fn ($entry) => ['text' => implode(' ', array_column($entry->payload['segments'] ?? [], 'text')), 'tokens' => [], 'translation' => '',
                    'metadata' => ['cacheId' => $entry->id, 'transcriptionModel' => $entry->transcription_model]])->values()->all();
        }

        return SubtitleTrack::query()->with('job')->where('youtube_video_id', $gold['videoId'])
            ->when($gold['targetLanguage'] ?? null, fn ($query, $language) => $query->where('target_language', $language))
            ->when($this->option('job'), fn ($query, $job) => $query->whereHas('job', fn ($query) => $query->where('public_id', $job)))
            ->get()->filter(fn ($track) => ($track->detected_source_language ?: $track->source_language) === $gold['language'])
            ->map(fn ($track) => ['text' => implode(' ', array_column($track->cues, 'sourceText')), 'tokens' => collect($track->cues)->flatMap(fn ($cue) => array_column($cue['tokens'] ?? [], 'text'))->all(),
                'translation' => implode(' ', array_column($track->cues, 'translatedText')), 'metadata' => ['jobId' => $track->job->public_id, 'trackId' => $track->public_id,
                    'provider' => $track->job->ai_provider, 'model' => $track->job->ai_model, 'processingVersion' => $track->processing_version]])->values()->all();
    }
}
