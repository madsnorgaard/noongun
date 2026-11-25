<?php

use App\Http\Controllers\Settings\LlmKeyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/drafting', [LlmKeyController::class, 'edit'])->name('llm-key.edit');

    // A key is a secret that spends money: storing one is limited tightly,
    // so the form cannot be used to try keys out.
    Route::put('settings/drafting/key', [LlmKeyController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('llm-key.update');

    Route::delete('settings/drafting/key', [LlmKeyController::class, 'destroy'])
        ->middleware('throttle:6,1')
        ->name('llm-key.destroy');
});
