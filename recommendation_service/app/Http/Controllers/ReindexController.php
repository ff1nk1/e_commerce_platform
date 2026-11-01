<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Catalog\ReindexProductsService;
use Illuminate\Http\JsonResponse;

final class ReindexController extends Controller
{
    public function __construct(
        private readonly ReindexProductsService $reindex,
    ) {}

    public function __invoke(): JsonResponse
    {
        $indexed = $this->reindex->handle();

        return response()->json([
            'indexed' => $indexed,
        ]);
    }
}
