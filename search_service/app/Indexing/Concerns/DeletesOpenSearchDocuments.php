<?php

declare(strict_types=1);

namespace App\Indexing\Concerns;

use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use Throwable;

trait DeletesOpenSearchDocuments
{
    abstract protected function openSearchClient(): Client;

    abstract protected function refreshOnWrite(): bool;

    protected function deleteDocument(string $index, string $id): void
    {
        try {
            $this->openSearchClient()->delete([
                'index' => $index,
                'id' => $id,
                'refresh' => $this->refreshOnWrite(),
            ]);
        } catch (Missing404Exception) {
            // already gone
        } catch (Throwable $e) {
            if ($this->isNotFound($e)) {
                return;
            }

            throw $e;
        }
    }

    protected function isNotFound(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'not_found') || str_contains($message, '404');
    }
}
