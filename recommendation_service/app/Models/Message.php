<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $conversation_id
 * @property string $role
 * @property string $content
 * @property array<string, mixed>|null $products
 * @property Carbon|null $created_at
 */
#[Fillable([
    'conversation_id',
    'role',
    'content',
    'products',
])]
final class Message extends Model
{
    use HasFactory;
    use HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'products' => 'array',
        ];
    }
}
