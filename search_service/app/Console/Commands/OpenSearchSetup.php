<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SearchIndexService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Create OpenSearch indices used by search_service (with nested attributes + synonym analyzers)')]
#[Signature('opensearch:setup
                            {--recreate : Delete existing products/categories indices (and aliases) then create fresh}
                            {--force : Skip confirmation when using --recreate}')]
final class OpenSearchSetup extends Command
{
    public function handle(SearchIndexService $index): int
    {
        if ($this->option('recreate')) {
            if (! $this->option('force') && ! $this->confirm('This will DELETE products/categories OpenSearch data and recreate empty indices. Continue?', true)) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }

            $index->recreateIndices();
            $this->info('OpenSearch indices recreated (empty). Re-publish products from catalog to refill.');

            return self::SUCCESS;
        }

        $index->ensureIndices();
        $this->info('OpenSearch indices are ready.');

        return self::SUCCESS;
    }
}
