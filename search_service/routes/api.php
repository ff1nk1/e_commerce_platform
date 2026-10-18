<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:search')->group(function (): void {
    Route::get('/search/autocomplete', [SearchController::class, 'autocomplete']);
    Route::get('/search', SearchController::class);
});
