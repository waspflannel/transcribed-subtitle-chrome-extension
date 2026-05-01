<?php

namespace App\Services\Transcription;

class TranscriptionOptions
{
    public function __construct(
        public readonly string $sourceLanguage,
    ) {}

    public function providerLanguage(): ?string
    {
        return $this->sourceLanguage === 'auto' ? null : $this->sourceLanguage;
    }
}
