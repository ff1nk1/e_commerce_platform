<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        $pgvector = false;
        $indexed = 0;

        try {
            DB::select('select 1');
            $dbOk = true;
            $pgvector = (bool) DB::selectOne(
                "SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'vector') AS present"
            )->present;
            $indexed = (int) ProductCatalog::query()->whereNotNull('embedding')->count();
        } catch (\Throwable) {
            // leave flags false
        }

        $llmConfigured = filled(config('llm.gemini.api_key'));

        $status = $dbOk && $pgvector ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status,
            'database' => $dbOk,
            'pgvector' => $pgvector,
            'indexed_products' => $indexed,
            'llm_configured' => $llmConfigured,
            'llm_driver' => config('llm.driver'),
        ], $status === 'ok' ? 200 : 503);
    }
}
