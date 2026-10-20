<?php

namespace App\Search;

class ProductSearchResponseMapper
{
    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    public function mapHits(array $response): array
    {
        $products = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $source = $hit['_source'] ?? [];
            $products[] = [
                'id' => $source['id'] ?? (int) ($hit['_id'] ?? 0),
                'name' => $source['name'] ?? '',
                'description' => $source['description'] ?? null,
                'price' => $source['price'] ?? null,
                'image_url' => $source['image_url'] ?? null,
                'category_id' => $source['category_id'] ?? null,
                'category_name' => $source['category_name'] ?? null,
                'store_id' => $source['store_id'] ?? null,
                'store_name' => $source['store_name'] ?? null,
                'in_stock' => (bool) ($source['in_stock'] ?? false),
                'quantity' => (int) ($source['quantity'] ?? 0),
                'attributes' => $source['attributes'] ?? [],
            ];
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $aggregations
     * @return array<string, mixed>
     */
    public function mapFacets(array $aggregations): array
    {
        $root = $aggregations['catalog_facets'] ?? $aggregations;

        $categories = [];
        foreach ($root['categories']['buckets'] ?? [] as $bucket) {
            $nameBucket = $bucket['name']['buckets'][0]['key'] ?? null;
            $categories[] = [
                'id' => (int) $bucket['key'],
                'name' => $nameBucket !== null ? (string) $nameBucket : (string) $bucket['key'],
                'count' => (int) ($bucket['doc_count'] ?? 0),
            ];
        }

        $priceRanges = [];
        $priceBuckets = $root['price_ranges']['buckets'] ?? [];
        foreach (ProductSearchQueryBuilder::PRICE_RANGES as $range) {
            $bucket = $priceBuckets[$range['key']] ?? null;
            $priceRanges[] = [
                'key' => $range['key'],
                'label' => $range['label'],
                'from' => $range['from'],
                'to' => $range['to'],
                'count' => (int) ($bucket['doc_count'] ?? 0),
            ];
        }

        $availability = [];
        foreach ($root['availability']['buckets'] ?? [] as $bucket) {
            $key = $bucket['key'];
            $availability[] = [
                'value' => is_bool($key) ? $key : ((int) $key === 1 || $key === 'true'),
                'count' => (int) ($bucket['doc_count'] ?? 0),
            ];
        }

        $attributes = [];
        foreach ($root['attributes']['names']['buckets'] ?? [] as $nameBucket) {
            $name = (string) $nameBucket['key'];
            $values = [];
            foreach ($nameBucket['values']['buckets'] ?? [] as $valueBucket) {
                $values[] = [
                    'value' => (string) $valueBucket['key'],
                    'count' => (int) ($valueBucket['doc_count'] ?? 0),
                ];
            }
            $attributes[$name] = $values;
        }

        return [
            'categories' => $categories,
            'price_ranges' => $priceRanges,
            'availability' => $availability,
            'attributes' => $attributes,
        ];
    }
}
