<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', static fn (): array => ['status' => 'ok'])->name('health');
