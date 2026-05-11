<?php

namespace App\Models;

use Database\Factories\SubtitleJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubtitleJob extends Model
{
    /** @use HasFactory<SubtitleJobFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'youtube_video_id',
        'youtube_url',
        'video_duration_seconds',
        'source_language',
        'target_language',
        'processing_version',
        'status',
        'stage',
        'progress_percent',
        'error_code',
        'error_message',
        'install_id',
        'request_ip',
        'expires_at',
    ];

    public function track(): HasOne
    {
        return $this->hasOne(SubtitleTrack::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'progress_percent' => 'integer',
            'video_duration_seconds' => 'integer',
        ];
    }
}
