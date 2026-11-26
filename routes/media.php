<?php

use App\Http\Controllers\Media\MediaController;
use Illuminate\Support\Facades\Route;

Route::post('media', [MediaController::class, 'store'])
    ->middleware(['auth', 'verified', 'throttle:media-uploads'])
    ->name('media.store');

// No auth middleware here: it would send a visitor to the sign-in page and
// so confirm that the image exists. The controller answers 404 to everybody
// who is not a member of the workspace the image belongs to.
Route::where(['media' => '[0-9]{1,18}'])->group(function () {
    Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
    Route::get('media/{media}/thumb', [MediaController::class, 'thumb'])->name('media.thumb');
});
