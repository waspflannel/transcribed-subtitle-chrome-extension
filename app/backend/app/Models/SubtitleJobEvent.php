<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtitleJobEvent extends Model
{
    protected $fillable = [
        'subtitle_job_id',
        'public_job_id',
        'run_id',
        'event',
        'stage',
        'status',
        'queue_connection',
        'queue',
        'laravel_job_uuid',
        'laravel_batch_id',
        'batch_index',
        'worker_pid',
        'attempt',
        'duration_ms',
        'wait_ms',
        'error_code',
        'exception',
        'context',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SubtitleJob::class, 'subtitle_job_id');
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'batch_index' => 'integer',
            'context' => 'array',
            'duration_ms' => 'integer',
            'wait_ms' => 'integer',
            'worker_pid' => 'integer',
        ];
    }
}
