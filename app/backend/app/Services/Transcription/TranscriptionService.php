<?php

namespace App\Services\Transcription;

use App\Services\Audio\TemporaryAudioFile;

interface TranscriptionService
{
    public function transcribe(TemporaryAudioFile $audio, string $sourceLanguage): TimestampedTranscript;
}
