<?php

use App\Http\Controllers\Api\LearningTokenController;
use App\Http\Controllers\Api\SubtitleJobController;
use App\Http\Middleware\RequireExtensionInstallId;
use Illuminate\Support\Facades\Route;

Route::get('/up', static fn (): array => ['status' => 'ok'])->name('health');

Route::prefix('v1')
    ->middleware([RequireExtensionInstallId::class, 'throttle:subtitle-api'])
    ->group(function (): void {
        Route::get('/subtitle-jobs', [SubtitleJobController::class, 'index'])
            ->name('subtitle-jobs.index');

        Route::get('/subtitle-jobs/{jobId}', [SubtitleJobController::class, 'show'])
            ->name('subtitle-jobs.show');

        Route::post('/subtitle-jobs', [SubtitleJobController::class, 'store'])
            ->name('subtitle-jobs.store');

        Route::post('/learning-tokens', [LearningTokenController::class, 'store'])
            ->name('learning-tokens.store');
    });
