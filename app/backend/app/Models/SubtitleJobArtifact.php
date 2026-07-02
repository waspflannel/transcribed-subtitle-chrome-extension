<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleJobArtifact extends Model
{
    protected $fillable = [
        'subtitle_job_id',
        'artifact_type',
        'batch_index',
        'run_id',
        'payload',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SubtitleJob::class, 'subtitle_job_id');
    }

    protected function casts(): array
    {
        return [
            'batch_index' => 'integer',
            'payload' => 'array',
        ];
    }
}
