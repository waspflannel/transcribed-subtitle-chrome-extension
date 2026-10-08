<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleTrackLyricsCorrection extends Model
{
    protected $fillable = [
        'subtitle_track_id',
        'attempt_id',
        'ai_provider',
        'ai_model',
        'ai_fast_mode',
        'status',
        'lyrics',
        'work_revision',
        'work_state',
        'error_code',
        'error_message',
    ];

    public function track(): BelongsTo
    {
        return $this->belongsTo(SubtitleTrack::class, 'subtitle_track_id');
    }

    protected function casts(): array
    {
        return [
            'ai_fast_mode' => 'boolean',
            'lyrics' => 'encrypted',
            'work_revision' => 'integer',
            'work_state' => 'encrypted:array',
        ];
    }
}
