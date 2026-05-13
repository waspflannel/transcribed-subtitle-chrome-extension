<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subtitles:prune-expired')->daily();
