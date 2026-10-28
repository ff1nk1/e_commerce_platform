<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
    Route::post('/registration', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');
});

Route::post('/refresh', [AuthController::class, 'refresh'])
    ->middleware('throttle:30,1');

// Stripe webhook — no JWT (external callback)
Route::post('/webhooks/stripe', [GatewayController::class, 'stripeWebhook']);

// Search — public transparent proxy (no auth)
Route::get('/search/autocomplete', [GatewayController::class, 'searchAutocomplete']);
Route::get('/search', [GatewayController::class, 'search']);

// Recommendations health — public probe
Route::get('/recommendations/health', [GatewayController::class, 'recommendationsHealth']);

Route::middleware(['ensure.token', 'auth:api'])->group(function (): void {
    Route::get('/user', [AuthController::class, 'getUser']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::patch('/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/profile/password', [AuthController::class, 'changePassword']);

    // Storefront catalog — any authenticated role
    Route::any('/catalog/{path?}', [GatewayController::class, 'catalog'])
        ->where('path', '.*');

    // Customer orders — auth injects user_id when proxying
    Route::any('/orders/{path?}', [GatewayController::class, 'orders'])
        ->where('path', '.*');

    // Recommendations chat + conversations — inject user_id
    Route::middleware('role:admin')->post('/recommendations/reindex', [GatewayController::class, 'recommendationsReindex']);
    Route::any('/recommendations/{path?}', [GatewayController::class, 'recommendations'])
        ->where('path', '.*');

    // Notifications + admin orders + analytics — admin + analyst
    Route::middleware('role:admin,analyst')->group(function (): void {
        Route::any('/notifications/{path?}', [GatewayController::class, 'notifications'])
            ->where('path', '.*');

        // Must be registered before admin catalog catch-all
        Route::any('/admin/orders/{path?}', [GatewayController::class, 'ordersAdmin'])
            ->where('path', '.*');

        Route::any('/analytics/{path?}', [GatewayController::class, 'analytics'])
            ->where('path', '.*');
    });

    // Admin zone (catalog CRUD + users)
    Route::middleware('role:admin')->prefix('admin')->group(function (): void {
        Route::get('/users', [AdminController::class, 'index']);
        Route::get('/roles', [AdminController::class, 'getRoles']);
        Route::patch('/users/{id}/role', [AdminController::class, 'changeRole']);

        Route::any('/{path?}', [GatewayController::class, 'catalogAdmin'])
            ->where('path', '.*');
    });

    Route::middleware('role:admin')->post('/get-upload-url', [GatewayController::class, 'uploadUrl']);
});
