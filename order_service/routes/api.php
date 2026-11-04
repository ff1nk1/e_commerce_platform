<?php

declare(strict_types=1);

use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\DenyAnalystWrites;
use App\Http\Middleware\ResolveGatewayIdentity;
use Illuminate\Support\Facades\Route;

Route::get('/orders', [OrderController::class, 'index'])->middleware(ResolveGatewayIdentity::class);
Route::post('/orders', [OrderController::class, 'store'])->middleware(ResolveGatewayIdentity::class);
Route::get('/orders/{id}', [OrderController::class, 'show'])->middleware(ResolveGatewayIdentity::class)->whereNumber('id');
Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->middleware(ResolveGatewayIdentity::class)->whereNumber('id');
Route::post('/orders/{id}/pay', [OrderController::class, 'pay'])->middleware(ResolveGatewayIdentity::class)->whereNumber('id');

// Admin orders — analyst is read-only, enforced from the gateway role header.
Route::middleware(DenyAnalystWrites::class)->group(function (): void {
    Route::get('/admin/orders', [AdminOrderController::class, 'index']);
    Route::get('/admin/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id');
    Route::post('/admin/orders/{id}/ship', [AdminOrderController::class, 'ship'])->whereNumber('id');
});

Route::post('/webhooks/stripe', StripeWebhookController::class);
