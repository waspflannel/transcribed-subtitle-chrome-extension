<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\File;

class TemporaryAudioFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $directory,
        public readonly int $durationSeconds,
        public readonly int $sizeBytes,
        public readonly string $mimeType,
    ) {}

    public function delete(): void
    {
        if (File::exists($this->path)) {
            File::delete($this->path);
        }

        if (File::isDirectory($this->directory)) {
            File::deleteDirectory($this->directory);
        }
    }
}
