<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ComposeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MessageFlagController;
use App\Http\Controllers\Oauth\GoogleController;
use App\Http\Controllers\OutboxController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\ThreadActionController;
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
    Route::get('messages/{message}/attachments/{attachment}', [AttachmentController::class, 'show'])
        ->scopeBindings()->name('messages.attachments.show');
    Route::post('threads/actions', [ThreadActionController::class, 'update'])->name('threads.actions');

    // Throttled: the button is a reassurance lever, not a way to hammer Gmail.
    Route::post('sync', [SyncController::class, 'store'])->middleware('throttle:6,1')->name('sync');

    Route::get('outbox', [OutboxController::class, 'index'])->name('outbox');
    Route::post('outbox/{outbound}/retry', [OutboxController::class, 'retry'])->name('outbox.retry');
    Route::delete('outbox/{outbound}', [OutboxController::class, 'discard'])->name('outbox.discard');

    Route::get('compose', [ComposeController::class, 'create'])->name('compose');
    Route::get('compose/prefill', [ComposeController::class, 'prefill'])->name('compose.prefill');
    Route::post('compose', [ComposeController::class, 'store'])->name('compose.store');
    Route::post('compose/attach', [ComposeController::class, 'attach'])->name('compose.attach');
    Route::get('compose/{outbound}', [ComposeController::class, 'edit'])->name('compose.edit');
    Route::patch('compose/{outbound}', [ComposeController::class, 'update'])->name('compose.update');
    Route::post('compose/{outbound}/send', [ComposeController::class, 'send'])->name('compose.send');
    Route::delete('compose/{outbound}', [ComposeController::class, 'destroy'])->name('compose.destroy');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/import-older', [AccountController::class, 'importOlder'])->name('accounts.import-older');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    // The callback path must match the redirect URI registered in Google Cloud
    // Console exactly, so it is spelled out here rather than nested under /oauth.
    Route::get('gmail/connect', [GoogleController::class, 'connect'])->name('gmail.connect');
    Route::get('gmail/callback', [GoogleController::class, 'callback'])->name('gmail.callback');

    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
});
