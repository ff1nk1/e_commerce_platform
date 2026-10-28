<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'topic',
    'event_type',
    'payload',
])]
final class OutboxEvent extends Model
{
    use HasFactory;

    /**
     * Автоматически преобразуем JSON из базы в массив в PHP и обратно.
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
