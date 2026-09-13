<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subtitles:prune-expired')->daily();
Schedule::command('billing:prune-webhook-events')->daily();
Schedule::command('queue:prune-batches --hours=720 --cancelled=720')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('subtitles:fail-stalled-jobs')->everyFiveMinutes();
