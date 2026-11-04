<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\StoreResource;
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

        $paginator = Store::query()->orderBy('name')->paginate($perPage);

        return response()->json(
            $paginator->through(static fn (Store $store): array => StoreResource::make($store)->resolve())
        );
    }

    public function show(Store $store): JsonResponse
    {
        return response()->json(['store' => StoreResource::make($store)]);
    }
}
