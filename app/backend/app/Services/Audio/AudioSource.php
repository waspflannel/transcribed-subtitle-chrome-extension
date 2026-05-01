<?php

namespace App\Services\Audio;

interface AudioSource
{
    public function acquire(string $videoId, ?string $youtubeUrl, ?int $requestDurationSeconds): TemporaryAudioFile;
}
