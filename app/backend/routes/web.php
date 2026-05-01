<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', static fn (): array => ['status' => 'ok'])->name('health');
