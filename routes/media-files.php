<?php

use App\Http\Controllers\Media\MediaController;
use App\Http\Middleware\ReadSessionOnly;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

// An image is not a page. This file is loaded outside the "web" group, and
// the two routes take only what they need, in this order: the headers, the
// cookie that names the session, the session itself without a single write
// to it (ReadSessionOnly), and the workspace of the person it belongs to.
//
// No auth middleware either: it would send a visitor to the sign-in page
// and so confirm that the image exists. The controller answers 404 to
// everybody who is not a member of the workspace the image belongs to.
Route::middleware([SecurityHeaders::class, EncryptCookies::class, ReadSessionOnly::class, ResolveCurrentWorkspace::class])
    ->where(['media' => '[0-9]{1,18}'])
    ->group(function () {
        Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
        Route::get('media/{media}/thumb', [MediaController::class, 'thumb'])->name('media.thumb');
    });
