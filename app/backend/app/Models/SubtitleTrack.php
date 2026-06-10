<?php

namespace App\Models;

use Database\Factories\SubtitleTrackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleTrack extends Model
{
    /** @use HasFactory<SubtitleTrackFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'subtitle_job_id',
        'youtube_video_id',
        'source_language',
        'detected_source_language',
        'target_language',
        'source_dialect',
        'processing_version',
        'generated_at',
        'expires_at',
        'cues',
        'web_vtt',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SubtitleJob::class, 'subtitle_job_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function effectiveSourceLanguage(): string
    {
        return $this->detected_source_language ?: $this->source_language;
    }

    protected function casts(): array
    {
        return [
            'cues' => 'array',
            'expires_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
        ];
    }
}
