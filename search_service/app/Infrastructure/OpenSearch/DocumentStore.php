<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use Throwable;

final class DocumentStore
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function delete(string $index, string $id, bool $refresh): void
    {
        try {
            $this->client->delete([
                'index' => $index,
                'id' => $id,
                'refresh' => $refresh,
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

    private function isNotFound(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'not_found') || str_contains($message, '404');
    }
}
