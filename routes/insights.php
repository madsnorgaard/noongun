<?php

use App\Http\Controllers\Insights\InsightsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('insights', [InsightsController::class, 'index'])->name('insights.index');
});
