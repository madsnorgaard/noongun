<?php

use App\Http\Controllers\Media\MediaController;
use Illuminate\Support\Facades\Route;

// Uploading is an action on a page and has the middleware of pages. Showing
// an image is not: see routes/media-files.php.
Route::post('media', [MediaController::class, 'store'])
    ->middleware(['auth', 'verified', 'throttle:media-uploads'])
    ->name('media.store');
