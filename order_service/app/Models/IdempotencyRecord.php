<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted idempotent HTTP response, written in the same DB transaction as the
 * domain change it describes (no TTL, no drift from Redis).
 *
 * @property string $scope_key
 * @property array<string, mixed> $response
 */
#[Table(name: 'idempotency_keys')]
#[Fillable([
    'scope_key',
    'response',
])]
final class IdempotencyRecord extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response' => 'array',
        ];
    }
}
