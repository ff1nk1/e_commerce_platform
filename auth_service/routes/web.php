<?php

declare(strict_types=1);

use App\Http\Controllers\Gateway\GatewayController;
use Illuminate\Support\Facades\Route;

// Catalog Filament panel — JWT + admin role, then HTML proxy with identity headers
Route::middleware(['ensure.token', 'auth:api', 'role:admin'])->group(function (): void {
    Route::any('/admin-catalog/{path?}', [GatewayController::class, 'catalogAdminPanel'])
        ->where('path', '.*');
});
