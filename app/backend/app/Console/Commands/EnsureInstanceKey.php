<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('instance:ensure-key')]
#[Description('Generate an application key only when this instance has none.')]
class EnsureInstanceKey extends Command
{
    public function handle(): int
    {
        if (filled(config('app.key'))) {
            $this->components->info('Existing application key preserved.');

            return self::SUCCESS;
        }

        if ($this->laravel->configurationIsCached()) {
            $this->components->error('Run config:clear before checking for a missing application key.');

            return self::FAILURE;
        }

        $this->call('key:generate', ['--force' => true]);

        return filled(config('app.key')) ? self::SUCCESS : self::FAILURE;
    }
}
