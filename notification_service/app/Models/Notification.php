<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;

/**
 * @property string|null $_id
 * @property string|null $id
 * @property string|null $message_id
 * @property string $email
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property int $retries
 * @property bool $moved_to_dlq
 * @property string|null $error_message
 * @property string|null $name
 * @property int|null $time
 * @property string|null $event_type
 *
 * @method static NotificationFactory factory($count = null, $state = [])
 */
#[Connection('mongodb')]
#[Fillable([
    'message_id',
    'email',
    'payload',
    'status',
    'retries',
    'moved_to_dlq',
    'error_message',
    'name',
    'time',
    'event_type',
])]
final class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    /**
     * Kept as the historical collection name to avoid a data migration.
     *
     * Must stay protected: the parent Eloquent model reads it via reflection.
     * PrivatizeFinalClassPropertyRector is skipped for this file in /rector.php.
     */
    protected string $collection = 'email_logs';

    public function getTable(): string
    {
        return $this->collection;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'moved_to_dlq' => 'boolean',
            'retries' => 'integer',
            'time' => 'integer',
        ];
    }

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
