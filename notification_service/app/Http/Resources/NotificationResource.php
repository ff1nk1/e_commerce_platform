<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Public notification/email-log view. Whitelists the fields exposed to the
 * admin UI; the Mongo `_id` is normalized to a string `id`.
 *
 * @property string|null $id
 * @property string|null $message_id
 * @property string $email
 * @property string|null $name
 * @property string|null $event_type
 * @property string $status
 * @property int|null $retries
 * @property bool $moved_to_dlq
 * @property string|null $error_message
 * @property array<string, mixed>|null $payload
 * @property int|null $time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'email' => $this->email,
            'name' => $this->name,
            'event_type' => $this->event_type,
            'status' => $this->status,
            'retries' => (int) ($this->retries ?? 0),
            'moved_to_dlq' => (bool) $this->moved_to_dlq,
            'error_message' => $this->error_message,
            'payload' => $this->payload,
            'time' => $this->time,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
