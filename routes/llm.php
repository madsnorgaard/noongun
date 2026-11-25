<?php

use App\Http\Controllers\Settings\LlmKeyController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/drafting', [LlmKeyController::class, 'edit'])->name('llm-key.edit');

    // A key is a secret that spends money. Storing one is limited tightly,
    // so the form cannot be used to try keys out, and changing or removing
    // it needs a recently confirmed password, as the security settings do,
    // so that a session someone else got hold of cannot swap the key.
    // Without a confirmation the request is sent to the password page and
    // from there back to the settings page; the key is entered again.
    Route::put('settings/drafting/key', [LlmKeyController::class, 'update'])
        ->middleware(['throttle:6,1', RequirePassword::class])
        ->name('llm-key.update');

    // Choosing the provider decides which company is sent the brief and
    // whose account pays, so it is guarded the same way.
    Route::put('settings/drafting/provider', [LlmKeyController::class, 'choose'])
        ->middleware(['throttle:6,1', RequirePassword::class])
        ->name('llm-key.choose');

    Route::delete('settings/drafting/key', [LlmKeyController::class, 'destroy'])
        ->middleware(['throttle:6,1', RequirePassword::class])
        ->name('llm-key.destroy');
});
