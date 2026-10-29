<?php

declare(strict_types=1);

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReindexController;
use Illuminate\Support\Facades\Route;

Route::prefix('recommendations')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::post('/chat', ChatController::class)->middleware('throttle:chat');
    Route::get('/conversations/{id}', [ConversationController::class, 'show']);
    Route::delete('/conversations/{id}', [ConversationController::class, 'destroy']);
    Route::post('/reindex', ReindexController::class);
});
