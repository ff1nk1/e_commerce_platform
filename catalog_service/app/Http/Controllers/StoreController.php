<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreController extends Controller
{
    /**
     * List stores for storefront or admin selects.
     */
    public function index(Request $request): JsonResponse
    {
        $minPerPage = 1;
        $maxPerPage = 100;
        $defaultPerPage = 50;
        $perPage = max($minPerPage, min($maxPerPage, (int) $request->input('per_page', $defaultPerPage)));

        return response()->json(
            Store::query()->orderBy('name')->paginate($perPage)
        );
    }

    public function show(Store $store): JsonResponse
    {
        return response()->json(['store' => $store]);
    }
}
