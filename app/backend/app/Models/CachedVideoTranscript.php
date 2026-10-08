<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A transcript cached per video, shared across users. Transcripts derive only
 * from public YouTube audio and the requested source language; no user data
 * is stored here. The key includes the transcription model id so model
 * upgrades invalidate cached rows.
 */
class CachedVideoTranscript extends Model
{
    protected $fillable = [
        'youtube_video_id',
        'requested_source_language',
        'transcription_model',
        'audio_duration_seconds',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'audio_duration_seconds' => 'integer',
            'payload' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
