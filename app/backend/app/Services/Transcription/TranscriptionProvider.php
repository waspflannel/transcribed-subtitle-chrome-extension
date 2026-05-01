<?php

namespace App\Services\Transcription;

use App\Services\Audio\TemporaryAudioFile;

interface TranscriptionProvider
{
    public function transcribe(TemporaryAudioFile $audio, TranscriptionOptions $options): TimestampedTranscript;
}
