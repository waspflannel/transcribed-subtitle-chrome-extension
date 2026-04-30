<?php

namespace App\Models;

use App\SubtitleJobStatus;
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
        'options',
        'processing_version',
        'status',
        'progress_stage',
        'progress_percent',
        'progress_message',
        'error_code',
        'error_message',
        'error_details',
        'install_id',
        'request_ip',
        'expires_at',
    ];

    protected $attributes = [
        'status' => 'queued',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function track(): HasOne
    {
        return $this->hasOne(SubtitleTrack::class);
    }

    protected function casts(): array
    {
        return [
            'error_details' => 'array',
            'expires_at' => 'immutable_datetime',
            'options' => 'array',
            'status' => SubtitleJobStatus::class,
            'video_duration_seconds' => 'integer',
            'progress_percent' => 'integer',
        ];
    }
}
