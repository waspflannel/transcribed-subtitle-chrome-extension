<?php

namespace App\Models;

use Database\Factories\SubtitleJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubtitleJob extends Model
{
    /** @use HasFactory<SubtitleJobFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'user_id',
        'run_id',
        'youtube_video_id',
        'youtube_url',
        'video_duration_seconds',
        'source_language',
        'detected_source_language',
        'target_language',
        'processing_version',
        'generation_tier',
        'enrichment_mode',
        'include_romanization',
        'include_translation',
        'status',
        'stage',
        'progress_percent',
        'estimated_provider_cost_microusd',
        'error_code',
        'error_message',
        'install_id',
        'expires_at',
    ];

    public function track(): HasOne
    {
        return $this->hasOne(SubtitleTrack::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(SubtitleJobArtifact::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubtitleJobEvent::class);
    }

    public function hasReadyTrack(): bool
    {
        $track = $this->relationLoaded('track')
            ? $this->track
            : $this->track()->first();

        return $track !== null && ! $track->isExpired();
    }

    public function effectiveSourceLanguage(): string
    {
        return $this->detected_source_language ?: $this->source_language;
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'estimated_provider_cost_microusd' => 'integer',
            'include_romanization' => 'boolean',
            'include_translation' => 'boolean',
            'progress_percent' => 'integer',
            'video_duration_seconds' => 'integer',
        ];
    }
}
