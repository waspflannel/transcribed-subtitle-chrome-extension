<?php

use App\Http\Controllers\Api\ExtensionAuthController;
use App\Http\Controllers\Api\LearningTokenController;
use App\Http\Controllers\Api\SubtitleJobController;
use App\Http\Middleware\RequireExtensionInstallId;
use App\Support\ExtensionTokenAbility;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('/up', static fn (): array => ['status' => 'ok'])->name('health');

Route::prefix('v1')
    ->middleware(RequireExtensionInstallId::class)
    ->group(function (): void {
        Route::post('/extension-auth/login', [ExtensionAuthController::class, 'login'])
            ->name('extension-auth.login')
            ->middleware('throttle:extension-auth');

        Route::get('/extension-auth/account', [ExtensionAuthController::class, 'account'])
            ->name('extension-auth.account')
            ->middleware([
                'auth:sanctum',
                CheckAbilities::class.':'.ExtensionTokenAbility::ACCOUNT_READ,
            ]);

        Route::post('/extension-auth/logout', [ExtensionAuthController::class, 'logout'])
            ->name('extension-auth.logout')
            ->middleware([
                'auth:sanctum',
                CheckAbilities::class.':'.ExtensionTokenAbility::TOKENS_REVOKE,
            ]);

        Route::middleware([
            'auth:sanctum',
            CheckAbilities::class.':'.ExtensionTokenAbility::SUBTITLES_WRITE,
        ])
            ->group(function (): void {
                Route::get('/subtitle-jobs', [SubtitleJobController::class, 'index'])
                    ->name('subtitle-jobs.index')
                    ->middleware('throttle:subtitle-status-api');

                Route::get('/subtitle-jobs/{jobId}', [SubtitleJobController::class, 'show'])
                    ->name('subtitle-jobs.show')
                    ->middleware('throttle:subtitle-status-api');

                Route::delete('/subtitle-jobs/{jobId}', [SubtitleJobController::class, 'cancel'])
                    ->name('subtitle-jobs.cancel')
                    ->middleware('throttle:subtitle-api');

                Route::get('/subtitle-jobs/{jobId}/partial-track', [SubtitleJobController::class, 'partialTrack'])
                    ->name('subtitle-jobs.partial-track')
                    ->middleware('throttle:subtitle-status-api');

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
    });
