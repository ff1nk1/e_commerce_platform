<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Catalog\ProductIndexer;
use Illuminate\Http\JsonResponse;

final class ReindexController extends Controller
{
    public function __construct(
        private readonly ProductIndexer $indexer,
    ) {}

    public function __invoke(): JsonResponse
    {
        $indexed = $this->indexer->reindexAll();

        return response()->json([
            'indexed' => $indexed,
        ]);
    }
}
