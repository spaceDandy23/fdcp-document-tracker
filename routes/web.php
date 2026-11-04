<?php

use App\Http\Controllers\FdcpAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [FdcpAuthenticationController::class, 'redirect'])->name('login');
    Route::get('/auth/callback', [FdcpAuthenticationController::class, 'callback'])
        ->name('auth.fdcp.callback');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', fn () => view('welcome'))->name('home');
    Route::post('/logout', [FdcpAuthenticationController::class, 'logout'])->name('logout');
});
