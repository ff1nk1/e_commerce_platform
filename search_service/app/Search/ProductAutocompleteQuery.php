<?php

declare(strict_types=1);

namespace App\Search;

final class ProductAutocompleteQuery
{
    /**
     * @return array{size: int, _source: list<string>, query: array<string, mixed>}
     */
    public function buildBody(string $q, int $limit, string|int $fuzziness): array
    {
        $limit = max(1, min(20, $limit));

        return [
            'size' => $limit,
            '_source' => ['id', 'name', 'price', 'image_url'],
            'query' => [
                'multi_match' => [
                    'query' => $q,
                    'type' => 'bool_prefix',
                    'fields' => [
                        'name.autocomplete',
                        'name.autocomplete._2gram',
                        'name.autocomplete._3gram',
                    ],
                    'fuzziness' => $fuzziness,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array{id: int, name: string, price: float|null, image_url: string|null}>
     */
    public function mapSuggestions(array $response): array
    {
        $suggestions = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $source = $hit['_source'] ?? [];
            $suggestions[] = [
                'id' => (int) ($source['id'] ?? ($hit['_id'] ?? 0)),
                'name' => (string) ($source['name'] ?? ''),
                'price' => isset($source['price']) ? (float) $source['price'] : null,
                'image_url' => $source['image_url'] ?? null,
            ];
        }

        return $suggestions;
    }
}
