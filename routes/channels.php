<?php

use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\ChannelSelectionController;
use App\Http\Controllers\Channels\ConnectChannelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');

    Route::get('channels/selection', [ChannelSelectionController::class, 'edit'])->name('channels.selection.edit');
    Route::put('channels/selection', [ChannelSelectionController::class, 'update'])->name('channels.selection.update');

    Route::post('channels/{platform}/connect', [ConnectChannelController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('channels.connect');

    Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])
        ->whereNumber('channel')
        ->name('channels.destroy');

    // The address registered with the platform as its OAuth redirect URI.
    // Changing this path breaks every existing platform app registration.
    Route::get('auth/{platform}/callback', [ConnectChannelController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('channels.callback');
});
