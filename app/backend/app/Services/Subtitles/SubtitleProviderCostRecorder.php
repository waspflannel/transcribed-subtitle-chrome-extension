<?php

namespace App\Services\Subtitles;

use App\Models\SubtitleJob;
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

    public function recordCueBatch(SubtitleJob $job, string $stage, int $cueCount): void
    {
        $purpose = match ($stage) {
            'tokenizing' => 'tokenization',
            'translating' => 'translation',
            'romanizing' => 'romanization',
            'enriching' => 'enrichment',
            default => null,
        };

        if ($purpose === null) {
            return;
        }

        $unitPrice = max(0, (int) config("subtitles.costs.openai_{$purpose}_microusd_per_cue", 0));

        $this->record(
            job: $job,
            stage: $stage,
            provider: Lab::OpenAI->value,
            model: (string) config('ai.providers.'.Lab::OpenAI->value.".models.{$purpose}.default"),
            billingUnit: 'cue',
            billedUnits: max(0, $cueCount),
            unitPriceMicrousd: $unitPrice,
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
    ): void {
        $costMicrousd = $billedUnits * $unitPriceMicrousd;

        if ($costMicrousd > 0) {
            SubtitleJob::query()
                ->whereKey($job->id)
                ->increment('estimated_provider_cost_microusd', $costMicrousd);
        }

        $this->tracer->jobEvent($job->refresh(), 'provider.cost_estimated', [
            'stage' => $stage,
            'provider' => $provider,
            'model' => $model,
            'billing_unit' => $billingUnit,
            'billed_units' => $billedUnits,
            'unit_price_microusd' => $unitPriceMicrousd,
            'cost_microusd' => $costMicrousd,
            'generation_tier' => $job->generation_tier,
        ]);
    }
}
