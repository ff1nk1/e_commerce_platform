<?php

namespace App\Providers;

use App\Services\Catalog\CatalogApiFreshnessChecker;
use App\Services\Catalog\Contracts\CatalogProductClient;
use App\Services\Catalog\Contracts\ProductFreshnessChecker;
use App\Services\Catalog\Contracts\RelevanceGate;
use App\Services\Catalog\CosineDistanceRelevanceGate;
use App\Services\Catalog\HttpCatalogProductClient;
use App\Services\Catalog\SearchFiltersNormalizer;
use App\Services\Llm\Contracts\LlmClient;
use App\Services\Llm\Gemini\GeminiLlmClient;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LlmClient::class, function (): LlmClient {
            $driver = (string) config('llm.driver', 'gemini');

            return match ($driver) {
                'gemini' => new GeminiLlmClient(
                    apiKey: (string) config('llm.gemini.api_key', ''),
                    baseUrl: (string) config('llm.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'),
                    chatModel: (string) config('llm.gemini.chat_model', 'gemini-3.8-flash'),
                    embeddingModel: (string) config('llm.gemini.embedding_model', 'gemini-embedding-001'),
                    temperature: (float) config('llm.gemini.temperature', 0.3),
                    embeddingDimensions: (int) config('llm.gemini.embedding_dimensions', 768),
                    timeout: max(1, (int) config('llm.gemini.timeout', 12)),
                ),
                default => throw new InvalidArgumentException("Unsupported LLM_DRIVER [{$driver}]"),
            };
        });

        $this->app->singleton(SearchFiltersNormalizer::class, function (): SearchFiltersNormalizer {
            return new SearchFiltersNormalizer(
                requireInStock: (bool) config('recommendations.require_in_stock', true),
            );
        });

        $this->app->bind(RelevanceGate::class, function (): RelevanceGate {
            return new CosineDistanceRelevanceGate(
                maxCosineDistance: (float) config('recommendations.max_cosine_distance', 0.75),
            );
        });

        $this->app->bind(CatalogProductClient::class, function (): CatalogProductClient {
            return new HttpCatalogProductClient(
                baseUrl: (string) config('recommendations.catalog.base_url', 'http://catalog_service_app:8002'),
                timeout: (int) config('recommendations.catalog.timeout', 5),
            );
        });

        $this->app->bind(ProductFreshnessChecker::class, CatalogApiFreshnessChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
