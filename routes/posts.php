<?php

use App\Http\Controllers\Posts\CalendarController;
use App\Http\Controllers\Posts\PostController;
use App\Http\Controllers\Posts\PostTransitionController;
use App\Http\Controllers\Posts\QueueController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('queue', [QueueController::class, 'index'])->name('queue.index');
    Route::post('queue/approve', [PostTransitionController::class, 'approveSelected'])->name('queue.approve');

    Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');

    Route::get('posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('posts', [PostController::class, 'store'])->name('posts.store');

    // Posts are looked up in the controllers, inside the current workspace.
    Route::where(['post' => '[0-9]{1,18}'])->group(function () {
        Route::get('posts/{post}', [PostController::class, 'show'])->name('posts.show');
        Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
        Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

        Route::post('posts/{post}/approve', [PostTransitionController::class, 'approve'])->name('posts.approve');
        Route::post('posts/{post}/reject', [PostTransitionController::class, 'reject'])->name('posts.reject');
        Route::post('posts/{post}/schedule', [PostTransitionController::class, 'schedule'])->name('posts.schedule');
        Route::post('posts/{post}/unschedule', [PostTransitionController::class, 'unschedule'])->name('posts.unschedule');
        Route::post('posts/{post}/return-to-draft', [PostTransitionController::class, 'returnToDraft'])->name('posts.reopen');
        Route::post('posts/{post}/retry', [PostTransitionController::class, 'retry'])->name('posts.retry');
    });
});
