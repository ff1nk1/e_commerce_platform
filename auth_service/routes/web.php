<?php

declare(strict_types=1);

use App\Http\Controllers\GatewayController;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): Factory|View {
    return view('welcome');
});

// Catalog Filament panel — JWT + admin role, then HTML proxy with identity headers
Route::middleware(['ensure.token', 'auth:api', 'role:admin'])->group(function (): void {
    Route::any('/admin-catalog/{path?}', [GatewayController::class, 'catalogAdminPanel'])
        ->where('path', '.*');
});
