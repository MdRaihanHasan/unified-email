<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MessageFlagController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/inbox');

    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::get('threads/{thread}', [InboxController::class, 'show'])->name('threads.show');

    Route::patch('messages/{message}/flags', [MessageFlagController::class, 'update'])->name('messages.flags');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts');

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
});
