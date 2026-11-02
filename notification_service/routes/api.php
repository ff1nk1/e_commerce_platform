<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationsController;
use App\Http\Middleware\RequireGatewayRole;
use Illuminate\Support\Facades\Route;

// Admin/analyst management API — protected by the gateway role header (PII).
Route::middleware(RequireGatewayRole::class)->group(function (): void {
    Route::get('/notifications', [NotificationsController::class, 'index']);
    Route::get('/notifications/{id}', [NotificationsController::class, 'show']);
    Route::post('/notifications/{id}/resend', [NotificationsController::class, 'resend']);
});
