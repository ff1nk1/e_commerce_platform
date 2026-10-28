<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationsController;
use Illuminate\Support\Facades\Route;

// Open management API for admin frontend — no auth middleware

Route::get('/notifications', [NotificationsController::class, 'index']);
Route::get('/notifications/{id}', [NotificationsController::class, 'show']);
Route::post('/notifications/{id}/resend', [NotificationsController::class, 'resend']);
