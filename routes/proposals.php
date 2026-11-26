<?php

use App\Http\Controllers\Drafting\IdeaDraftController;
use App\Http\Controllers\Drafting\ProposalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('proposals/create', [ProposalController::class, 'create'])->name('proposals.create');

    // Starting a proposal spends money. The limits in ProposeWeek are the
    // real guard; this only slows a client that keeps asking.
    Route::post('proposals', [ProposalController::class, 'store'])
        ->middleware('throttle:proposals')
        ->name('proposals.store');

    // "Stop waiting": closes the open draft runs of the workspace. It
    // spends nothing, and is throttled like the start.
    Route::post('proposals/stop', [ProposalController::class, 'stop'])
        ->middleware('throttle:proposals')
        ->name('proposals.stop');

    // "Draft a post" on one idea. The idea is looked up in the controller,
    // inside the current workspace. Starting spends money and is guarded
    // and throttled like a proposal.
    Route::where(['idea' => '[0-9]{1,18}'])->group(function () {
        Route::get('ideas/{idea}/draft', [IdeaDraftController::class, 'create'])->name('ideas.draft.create');
        Route::post('ideas/{idea}/draft', [IdeaDraftController::class, 'store'])
            ->middleware('throttle:proposals')
            ->name('ideas.draft.store');
    });
});
