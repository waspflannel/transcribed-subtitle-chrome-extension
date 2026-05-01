<?php

use App\Http\Controllers\Api\SubtitleJobController;
use App\Http\Middleware\RequireExtensionInstallId;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([RequireExtensionInstallId::class, 'throttle:subtitle-api'])
    ->group(function (): void {
        Route::post('/subtitle-jobs', [SubtitleJobController::class, 'store'])
            ->name('subtitle-jobs.store');
    });
