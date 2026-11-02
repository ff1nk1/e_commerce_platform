<?php

declare(strict_types=1);

use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\DenyAnalystWrites;
use Illuminate\Support\Facades\Route;

Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id');
Route::post('/orders/{id}/pay', [OrderController::class, 'pay'])->whereNumber('id');

// Admin orders — analyst is read-only, enforced from the gateway role header.
Route::middleware(DenyAnalystWrites::class)->group(function (): void {
    Route::get('/admin/orders', [AdminOrderController::class, 'index']);
    Route::get('/admin/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id');
    Route::post('/admin/orders/{id}/ship', [AdminOrderController::class, 'ship'])->whereNumber('id');
});

Route::post('/webhooks/stripe', StripeWebhookController::class);
