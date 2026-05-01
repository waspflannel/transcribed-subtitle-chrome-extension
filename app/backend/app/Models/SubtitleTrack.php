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
        'target_language',
        'processing_version',
        'detected_dialect_label',
        'detected_dialect_confidence',
        'generated_at',
        'expires_at',
        'cues',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SubtitleJob::class, 'subtitle_job_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'cues' => 'array',
            'detected_dialect_confidence' => 'float',
            'expires_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
        ];
    }
}
