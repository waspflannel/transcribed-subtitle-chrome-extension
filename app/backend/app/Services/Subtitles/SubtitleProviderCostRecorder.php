<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Enums\Lab;

final class SubtitleProviderCostRecorder
{
    public function __construct(
        private readonly SubtitleRuntimeTracer $tracer,
    ) {}

    public function recordTranscription(SubtitleJob $job, int|float|null $audioDurationSeconds): void
    {
        $billedMinutes = max(1, (int) ceil(max(1, (float) $audioDurationSeconds) / 60));
        $unitPrice = max(0, (int) config('subtitles.costs.elevenlabs_scribe_microusd_per_minute', 0));
        if (($job->vocabulary_hints ?? []) !== []) {
            $unitPrice = (int) ceil($unitPrice * 1.2);
        }

        $this->record(
            job: $job,
            stage: 'transcribing',
            provider: Lab::ElevenLabs->value,
            model: (string) config('ai.providers.'.Lab::ElevenLabs->value.'.models.transcription.default'),
            billingUnit: 'audio_minute',
            billedUnits: $billedMinutes,
            unitPriceMicrousd: $unitPrice,
        );
    }

    /**
     * Record configured feature estimates against the shared analysis model.
     * These rows are estimates, not separate provider requests or actual usage.
     */
    public function recordAnalyzedCueBatch(SubtitleJob $job, int $cueCount, bool $includeTranslation = true, bool $includeRomanization = false): void
    {
        $model = (string) config('ai.providers.'.config('ai.default').'.models.analysis.default');

        $stages = ['tokenizing' => 'tokenization'];
        if ($includeTranslation) {
            $stages['translating'] = 'translation';
        }
        if ($includeRomanization) {
            $stages['romanizing'] = 'romanization';
        }
        foreach ($stages as $stage => $purpose) {
            $this->record(
                job: $job,
                stage: $stage,
                provider: config('ai.default'),
                model: $model,
                billingUnit: 'cue',
                billedUnits: max(0, $cueCount),
                unitPriceMicrousd: max(0, (int) config('subtitles.costs.'.config('ai.default')."_{$purpose}_microusd_per_cue", 0)),
            );
        }
    }

    /**
     * One structured alignment call resolves the whole pasted-lyrics prompt,
     * so record it as a single per-call unit against the analysis model.
     */
    public function recordCorrectionAlignment(SubtitleJob $job): void
    {
        $this->record(
            job: $job,
            stage: 'aligning',
            provider: config('ai.default'),
            model: (string) config('ai.providers.'.config('ai.default').'.models.analysis.default'),
            billingUnit: 'alignment_call',
            billedUnits: 1,
            unitPriceMicrousd: max(0, (int) config('subtitles.costs.'.config('ai.default').'_alignment_microusd_per_call', 0)),
            requiredStatus: 'completed',
        );
    }

    public function recordCueBatch(SubtitleJob $job, string $stage, int $cueCount, string $requiredStatus = 'running'): void
    {
        $purpose = match ($stage) {
            'tokenizing' => 'tokenization',
            'romanizing' => 'romanization',
            'enriching' => 'enrichment',
            default => null,
        };

        if ($purpose === null) {
            return;
        }

        $unitPrice = max(0, (int) config('subtitles.costs.'.config('ai.default')."_{$purpose}_microusd_per_cue", 0));

        $this->record(
            job: $job,
            stage: $stage,
            provider: config('ai.default'),
            model: (string) config('ai.providers.'.config('ai.default').".models.{$purpose}.default"),
            billingUnit: 'cue',
            billedUnits: max(0, $cueCount),
            unitPriceMicrousd: $unitPrice,
            requiredStatus: $requiredStatus,
        );
    }

    private function record(
        SubtitleJob $job,
        string $stage,
        string $provider,
        string $model,
        string $billingUnit,
        int $billedUnits,
        int $unitPriceMicrousd,
        string $requiredStatus = 'running',
    ): void {
        $costMicrousd = $billedUnits * $unitPriceMicrousd;

        DB::transaction(function () use ($job, $stage, $provider, $model, $billingUnit, $billedUnits, $unitPriceMicrousd, $costMicrousd, $requiredStatus): void {
            $job = SubtitleJobLock::current($job->id, $job->run_id);

            if ($job === null || $job->status !== $requiredStatus) {
                return;
            }

            if ($costMicrousd > 0) {
                $job->increment('estimated_provider_cost_microusd', $costMicrousd);
            }

            $this->tracer->jobEvent($job, 'provider.cost_estimated', [
                'stage' => $stage,
                'provider' => $provider,
                'model' => $model,
                'billing_unit' => $billingUnit,
                'billed_units' => $billedUnits,
                'unit_price_microusd' => $unitPriceMicrousd,
                'cost_microusd' => $costMicrousd,
                'generation_tier' => $job->generation_tier,
            ]);
        }, attempts: 5);
    }
}
