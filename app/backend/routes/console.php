<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subtitles:prune-expired')->daily();
Schedule::command('billing:prune-webhook-events')->daily();
Schedule::command('subtitles:fail-stalled-jobs')->everyFiveMinutes();
