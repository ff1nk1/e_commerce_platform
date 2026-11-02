<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTO\Search\AutocompleteQuery;
use App\DTO\Search\AutocompleteResult;
use App\Infrastructure\OpenSearch\ProductAutocompleteQuery;
use OpenSearch\Client;

final class AutocompleteProductsService
{
    private const MIN_AUTOCOMPLETE_CHARS = 2;

    public function __construct(
        private readonly Client $client,
        private readonly ProductAutocompleteQuery $autocompleteQuery,
    ) {}

    /**
     * Prefix-friendly product name suggestions (no facets / no search.performed).
     */
    public function handle(AutocompleteQuery $query): AutocompleteResult
    {
        $q = is_string($query->q) ? trim($query->q) : '';
        if (mb_strlen($q) < self::MIN_AUTOCOMPLETE_CHARS) {
            return new AutocompleteResult(suggestions: []);
        }

        $response = $this->client->search([
            'index' => (string) config('opensearch.indices.products', 'products'),
            'body' => $this->autocompleteQuery->buildBody($q, $query->limit, $query->fuzziness),
        ]);

        return new AutocompleteResult(
            suggestions: $this->autocompleteQuery->mapSuggestions($response),
        );
    }
}
