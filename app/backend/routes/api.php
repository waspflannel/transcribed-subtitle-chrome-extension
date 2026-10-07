<?php

use App\Http\Controllers\Api\CodexAccountController;
use App\Http\Controllers\Api\InstanceSettingsController;
use App\Http\Controllers\Api\LearningTokenController;
use App\Http\Controllers\Api\SubtitleJobController;
use App\Http\Middleware\RequireExtensionInstallId;
use Illuminate\Support\Facades\Route;

Route::get('/up', static fn (): array => ['status' => 'ok'])->name('health');

Route::prefix('v1')
    ->middleware(RequireExtensionInstallId::class)
    ->group(function (): void {
        Route::get('/settings', [InstanceSettingsController::class, 'show']);
        Route::put('/settings', [InstanceSettingsController::class, 'update']);

        Route::get('/codex', [CodexAccountController::class, 'show'])
            ->middleware('throttle:subtitle-status-api');
        Route::post('/codex/login', [CodexAccountController::class, 'login'])
            ->middleware('throttle:codex-login');
        Route::delete('/codex', [CodexAccountController::class, 'destroy'])
            ->middleware('throttle:codex-login');

        Route::post('/subtitle-audio/prefetch', [SubtitleJobController::class, 'prefetchAudio'])
            ->middleware('throttle:subtitle-prefetch');
        Route::get('/subtitle-jobs', [SubtitleJobController::class, 'index'])
            ->name('subtitle-jobs.index')
            ->middleware('throttle:subtitle-status-api');

        Route::get('/subtitle-jobs/{jobId}', [SubtitleJobController::class, 'show'])
            ->name('subtitle-jobs.show')
            ->middleware('throttle:subtitle-status-api');

        Route::delete('/subtitle-jobs/{jobId}', [SubtitleJobController::class, 'cancel'])
            ->name('subtitle-jobs.cancel')
            ->middleware('throttle:subtitle-api');

        Route::delete('/subtitle-generations/{jobId}', [SubtitleJobController::class, 'destroyGeneration'])
            ->name('subtitle-generations.destroy')
            ->middleware('throttle:subtitle-api');

        Route::post('/subtitle-jobs/{jobId}/lyrics', [SubtitleJobController::class, 'correctLyrics'])
            ->name('subtitle-jobs.lyrics.store')
            ->middleware('throttle:subtitle-api');

        Route::get('/subtitle-jobs/{jobId}/lyrics', [SubtitleJobController::class, 'lyricsCorrectionStatus'])
            ->name('subtitle-jobs.lyrics.show')
            ->middleware('throttle:subtitle-status-api');

        Route::delete('/subtitle-jobs/{jobId}/lyrics', [SubtitleJobController::class, 'cancelLyrics'])
            ->name('subtitle-jobs.lyrics.destroy')
            ->middleware('throttle:subtitle-api');

        Route::patch('/subtitle-jobs/{jobId}/cues/{cueId}/tokens/{tokenIndex}', [SubtitleJobController::class, 'quickFixToken'])
            ->name('subtitle-jobs.tokens.update')
            ->middleware('throttle:subtitle-api')
            ->where('tokenIndex', '[0-9]{1,4}');

        Route::post('/subtitle-jobs', [SubtitleJobController::class, 'store'])
            ->name('subtitle-jobs.store')
            ->middleware('throttle:subtitle-api');

        Route::post('/learning-tokens', [LearningTokenController::class, 'store'])
            ->name('learning-tokens.store')
            ->middleware('throttle:subtitle-api');
    });
