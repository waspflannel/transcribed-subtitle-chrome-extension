<?php

namespace App\Console\Commands;

use App\Services\Subtitles\SubtitleQueueWorkerBootstrapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subtitles:dev-workers {--summary : Show worker auto-start settings before checking processes}')]
#[Description('Start local subtitle queue workers from an explicit developer command.')]
class StartSubtitleDevWorkers extends Command
{
    public function handle(SubtitleQueueWorkerBootstrapper $workers): int
    {
        if ($this->option('summary')) {
            $this->table(['setting', 'value'], collect($workers->autoStartSummary())
                ->map(fn (mixed $value, string $key): array => [$key, $this->displayValue($value)])
                ->values()
                ->all());
        }

        $workers->ensureRunning();
        $this->components->info('Subtitle dev worker check complete.');

        return self::SUCCESS;
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $value;
    }
}
