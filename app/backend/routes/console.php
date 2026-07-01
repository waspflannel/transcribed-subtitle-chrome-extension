<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subtitles:prune-expired')->daily();
Schedule::command('subtitles:fail-stalled-jobs')->everyFiveMinutes();
