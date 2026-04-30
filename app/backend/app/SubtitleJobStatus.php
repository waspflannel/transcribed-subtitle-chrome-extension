<?php

namespace App;

enum SubtitleJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
