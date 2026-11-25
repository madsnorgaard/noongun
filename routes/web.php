<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FirstRunSetupController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('setup', [FirstRunSetupController::class, 'create'])->name('setup.create');
Route::post('setup', [FirstRunSetupController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('setup.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
