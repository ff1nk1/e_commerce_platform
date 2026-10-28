<?php

declare(strict_types=1);

use App\Http\Controllers\AdminCatalogController;
use App\Http\Controllers\AdminStockController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\AuthenticateFromGatewayHeaders;
use Illuminate\Support\Facades\Route;

// Storefront — public at service level (gateway still requires JWT for clients)
Route::prefix('catalog')->group(function (): void {
    Route::get('/categories', [CatalogController::class, 'show_categories']);
    Route::get('/categories/{cat_slug}/products', [CatalogController::class, 'show_products_by_cat_id']);
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/{store_id}/products', [CatalogController::class, 'show_products_by_store_id']);
    Route::get('/stores/{store}', [StoreController::class, 'show']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
});

// Admin + upload — require gateway admin headers (defense in depth)
Route::middleware([AuthenticateFromGatewayHeaders::class])->group(function (): void {
    Route::post('/get-upload-url', [UploadController::class, 'getUploadUrl']);

    Route::prefix('admin')->group(function (): void {
        Route::get('/stores', [StoreController::class, 'index']);
        Route::apiResource('products', ProductController::class);
        Route::apiResource('categories', AdminCatalogController::class);
        Route::get('/stocks', [AdminStockController::class, 'index']);
        Route::post('/stocks', [AdminStockController::class, 'store']);
        Route::get('/stocks/{stock}', [AdminStockController::class, 'show']);
        Route::patch('/stocks/{stock}', [AdminStockController::class, 'update']);
    });
});
