<?php

use App\Http\Controllers\Brief\BriefController;
use App\Http\Controllers\Brief\IdeaController;
use App\Http\Controllers\Brief\PillarController;
use App\Http\Controllers\Brief\SlotController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('brief', [BriefController::class, 'edit'])->name('brief.edit');
    Route::put('brief', [BriefController::class, 'update'])->name('brief.update');
    Route::get('brief/versions/{version}', [BriefController::class, 'showVersion'])
        ->where('version', '[0-9]{1,9}')
        ->name('brief.versions.show');

    Route::get('brief/pillars', [PillarController::class, 'index'])->name('pillars.index');
    Route::post('brief/pillars', [PillarController::class, 'store'])->name('pillars.store');

    Route::get('brief/slots', [SlotController::class, 'index'])->name('slots.index');
    Route::post('brief/slots', [SlotController::class, 'store'])->name('slots.store');

    Route::get('ideas', [IdeaController::class, 'index'])->name('ideas.index');
    Route::post('ideas', [IdeaController::class, 'store'])->name('ideas.store');

    // Records are looked up in the controllers, inside the current workspace.
    Route::where(['pillar' => '[0-9]{1,18}', 'slot' => '[0-9]{1,18}', 'idea' => '[0-9]{1,18}'])->group(function () {
        Route::put('brief/pillars/{pillar}', [PillarController::class, 'update'])->name('pillars.update');
        Route::delete('brief/pillars/{pillar}', [PillarController::class, 'destroy'])->name('pillars.destroy');

        Route::delete('brief/slots/{slot}', [SlotController::class, 'destroy'])->name('slots.destroy');

        Route::put('ideas/{idea}', [IdeaController::class, 'update'])->name('ideas.update');
        Route::delete('ideas/{idea}', [IdeaController::class, 'destroy'])->name('ideas.destroy');
    });
});
