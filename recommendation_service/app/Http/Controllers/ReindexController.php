<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ReindexCatalogEmbeddings;
use Illuminate\Http\JsonResponse;

final class ReindexController extends Controller
{
    public function __invoke(): JsonResponse
    {
        dispatch(new ReindexCatalogEmbeddings);

        return response()->json([
            'status' => 'queued',
        ], 202);
    }
}
