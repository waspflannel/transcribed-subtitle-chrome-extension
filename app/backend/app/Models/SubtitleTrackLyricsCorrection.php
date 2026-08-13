<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleTrackLyricsCorrection extends Model
{
    protected $fillable = [
        'subtitle_track_id',
        'attempt_id',
        'status',
        'lyrics',
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
            'lyrics' => 'encrypted',
        ];
    }
}
