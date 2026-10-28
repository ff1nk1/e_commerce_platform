<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchAutocompleteRequest;
use App\Http\Requests\SearchProductsRequest;
use App\Services\ProductSearchService;
use Illuminate\Http\JsonResponse;

final class SearchController extends Controller
{
    public function __invoke(SearchProductsRequest $request, ProductSearchService $search): JsonResponse
    {
        return response()->json($search->search(
            $request->searchParams(),
            $request->shouldSkipAnalytics(),
        ));
    }

    public function autocomplete(SearchAutocompleteRequest $request, ProductSearchService $search): JsonResponse
    {
        $params = $request->autocompleteParams();

        return response()->json($search->autocomplete(
            $params['q'],
            $params['limit'],
            $params['fuzziness'],
        ));
    }
}
