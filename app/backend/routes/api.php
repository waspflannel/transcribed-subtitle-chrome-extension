<?php

use App\Http\Controllers\Api\SubtitleJobController;
use App\Http\Controllers\Api\SubtitleTrackController;
use App\Http\Middleware\RequireExtensionInstallId;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([RequireExtensionInstallId::class, 'throttle:subtitle-api'])
    ->group(function (): void {
        Route::post('/subtitle-jobs', [SubtitleJobController::class, 'store'])
            ->name('subtitle-jobs.store');

        Route::get('/subtitle-jobs/{subtitleJob}', [SubtitleJobController::class, 'show'])
            ->whereUuid('subtitleJob')
            ->name('subtitle-jobs.show');

        Route::get('/tracks/lookup', [SubtitleTrackController::class, 'lookup'])
            ->name('tracks.lookup');

        Route::get('/tracks/{subtitleTrack}', [SubtitleTrackController::class, 'show'])
            ->whereUuid('subtitleTrack')
            ->name('tracks.show');
    });
