<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', fn (Request $request): View => view('dashboard', [
    'user' => $request->user(),
]))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
