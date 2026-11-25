<?php

use App\Http\Controllers\Drafting\ProposalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('proposals/create', [ProposalController::class, 'create'])->name('proposals.create');

    // Starting a proposal spends money. The limits in ProposeWeek are the
    // real guard; this only slows a client that keeps asking.
    Route::post('proposals', [ProposalController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('proposals.store');
});
