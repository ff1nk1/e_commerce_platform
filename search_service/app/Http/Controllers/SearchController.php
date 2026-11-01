<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchAutocompleteRequest;
use App\Http\Requests\SearchProductsRequest;
use App\Services\Search\AutocompleteProductsService;
use App\Services\Search\SearchProductsService;
use Illuminate\Http\JsonResponse;

final class SearchController extends Controller
{
    public function __invoke(SearchProductsRequest $request, SearchProductsService $search): JsonResponse
    {
        return response()->json($search->handle(
            $request->searchParams(),
            $request->shouldSkipAnalytics(),
        ));
    }

    public function autocomplete(SearchAutocompleteRequest $request, AutocompleteProductsService $autocomplete): JsonResponse
    {
        return response()->json($autocomplete->handle($request->autocompleteParams()));
    }
}
