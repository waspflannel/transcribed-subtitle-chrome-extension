<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubtitleJobArtifact extends Model
{
    protected $fillable = [
        'subtitle_job_id',
        'artifact_type',
        'batch_index',
        'run_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'batch_index' => 'integer',
            'payload' => 'array',
        ];
    }
}
