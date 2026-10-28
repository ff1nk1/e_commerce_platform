<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailLogFactory;
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
 * @method static EmailLogFactory factory($count = null, $state = [])
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
final class EmailLog extends Model
{
    /** @use HasFactory<EmailLogFactory> */
    use HasFactory;

    private string $collection = 'email_logs';

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

    protected static function newFactory(): EmailLogFactory
    {
        return EmailLogFactory::new();
    }
}
